//! Go `strconv.FormatFloat(v, 'g', -1, bits)` compatible formatting.

use crate::quote::{sql_cast_quoted, LiteralQuoteConfig};

pub fn narrow_float32(v: f64) -> f32 {
    v as f32
}

fn pack_float32(v: f64) -> f32 {
    v as f32
}

fn round_trips(s: &str, original: f64, bits: u32) -> bool {
    let parsed = match s.parse::<f64>() {
        Ok(v) => v,
        Err(_) => return false,
    };
    if original.is_nan() {
        return parsed.is_nan();
    }
    if original.is_infinite() {
        return parsed.is_infinite() && (parsed > 0.0) == (original > 0.0);
    }
    if bits == 32 {
        return pack_float32(parsed as f64) == pack_float32(original);
    }
    parsed == original
}

fn fmt_exponent(exp: i32) -> String {
    if exp >= 0 {
        return format!("e+{exp:02}");
    }
    let ae = exp.abs();
    if ae < 10 {
        return format!("e-{ae:02}");
    }
    format!("e{exp}")
}

fn pe_to_go_g(es: &str) -> Option<String> {
    let (head, exp_part) = es.split_once('e').or_else(|| es.split_once('E'))?;
    let sign: i32 = if exp_part.starts_with('-') { -1 } else { 1 };
    let exp_digits = exp_part.trim_start_matches(['+', '-']);
    let exp: i32 = exp_digits.parse::<i32>().ok()? * sign;

    let (d1, rest) = if let Some((d1, rest)) = head.split_once('.') {
        (d1, rest)
    } else {
        (head, "")
    };
    let mut sig = format!("{d1}{rest}");
    while sig.ends_with('0') && sig.len() > 1 {
        sig.pop();
    }
    if sig.is_empty() {
        sig.push('0');
    }
    let ndigits = sig.len();

    if (-4..6).contains(&exp) {
        let dec_pos = 1 + exp;
        let s = if dec_pos <= 0 {
            format!("0.{}{}", "0".repeat((-dec_pos) as usize), sig)
        } else if dec_pos >= ndigits as i32 {
            format!("{sig}{}", "0".repeat((dec_pos as usize) - ndigits))
        } else {
            let pos = dec_pos as usize;
            format!("{}.{}", &sig[..pos], &sig[pos..])
        };
        let s = if s.contains('.') {
            s.trim_end_matches('0').trim_end_matches('.').to_string()
        } else {
            s
        };
        return Some(s);
    }

    let body = if ndigits == 1 {
        sig
    } else {
        format!("{}.{}", &sig[..1], &sig[1..])
    };
    Some(body + &fmt_exponent(exp))
}

pub fn format_go_g(mut v: f64, bits: u32) -> String {
    if bits == 32 {
        v = f64::from(narrow_float32(v));
    }

    if v.is_nan() {
        return "NaN".to_string();
    }
    if v.is_infinite() {
        return if v < 0.0 { "-Inf".to_string() } else { "+Inf".to_string() };
    }
    if v == 0.0 && v.is_sign_negative() {
        return "-0".to_string();
    }

    let negative = v < 0.0;
    let av = if negative { -v } else { v };
    let max_p = if bits == 64 { 16 } else { 8 };
    let target = if bits == 64 { v } else { f64::from(narrow_float32(v)) };

    let mut best: Option<String> = None;
    for p in 0..=max_p {
        let es = format!("{av:.prec$e}", prec = p);
        if let Some(g) = pe_to_go_g(&es) {
            let candidate = if negative {
                format!("-{g}")
            } else {
                g
            };
            if round_trips(&candidate, target, bits) {
                if best.as_ref().is_none_or(|b| candidate.len() < b.len()) {
                    best = Some(candidate);
                }
            }
        }
    }

    best.unwrap_or_else(|| {
        if negative {
            format!("-{av:?}")
        } else {
            format!("{av:?}")
        }
    })
}

pub fn format_spanner_cli_float(v: f64, bits: u32) -> String {
    let v = if bits == 32 {
        f64::from(narrow_float32(v))
    } else {
        v
    };
    if v.is_nan() {
        return "NaN".to_string();
    }
    if v.is_infinite() {
        return if v < 0.0 { "-Inf".to_string() } else { "+Inf".to_string() };
    }
    if v == v.trunc() {
        return format!("{v:.0}");
    }
    format!("{v:.6}")
}

pub fn float64_to_literal(v: f64, quote_cfg: LiteralQuoteConfig) -> String {
    if v.is_nan() {
        return sql_cast_quoted("nan", "FLOAT64", quote_cfg);
    }
    if v.is_infinite() {
        let payload = if v < 0.0 { "-inf" } else { "inf" };
        return sql_cast_quoted(payload, "FLOAT64", quote_cfg);
    }
    let mut s = format_go_g(v, 64);
    if !s.contains('.') && !s.contains('e') && !s.contains('E') {
        s.push_str(".0");
    }
    s
}

pub fn float32_to_literal(v: f64, quote_cfg: LiteralQuoteConfig) -> String {
    let fv = narrow_float32(v);
    if fv.is_nan() {
        return sql_cast_quoted("nan", "FLOAT32", quote_cfg);
    }
    if fv.is_infinite() {
        let payload = if fv < 0.0 { "-inf" } else { "inf" };
        return sql_cast_quoted(payload, "FLOAT32", quote_cfg);
    }
    format!("CAST({} AS FLOAT32)", format_go_g(f64::from(fv), 32))
}
