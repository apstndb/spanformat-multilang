//! GoogleSQL literal quoting and escaping.


#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum QuoteStrategy {
    Legacy = 0,
    Always = 1,
    MinEscape = 2,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum PreferredQuote {
    Double = 0,
    Single = 1,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub struct LiteralQuoteConfig {
    pub strategy: QuoteStrategy,
    pub preferred_quote: PreferredQuote,
}

impl Default for LiteralQuoteConfig {
    fn default() -> Self {
        Self {
            strategy: QuoteStrategy::Legacy,
            preferred_quote: PreferredQuote::Double,
        }
    }
}

pub fn normalize_literal_quote(cfg: LiteralQuoteConfig) -> LiteralQuoteConfig {
    let strategy = match cfg.strategy {
        QuoteStrategy::Legacy | QuoteStrategy::Always | QuoteStrategy::MinEscape => cfg.strategy,
    };
    let preferred_quote = match cfg.preferred_quote {
        PreferredQuote::Double | PreferredQuote::Single => cfg.preferred_quote,
    };
    LiteralQuoteConfig {
        strategy,
        preferred_quote,
    }
}

pub fn quote_char_for_payload(payload: &[u8], cfg: LiteralQuoteConfig) -> char {
    let cfg = normalize_literal_quote(cfg);
    if cfg.strategy == QuoteStrategy::Always {
        return if cfg.preferred_quote == PreferredQuote::Single {
            '\''
        } else {
            '"'
        };
    }

    let pref = if cfg.preferred_quote == PreferredQuote::Single {
        b'\''
    } else {
        b'"'
    };
    let other = if pref == b'\'' { b'"' } else { b'\'' };

    if cfg.strategy == QuoteStrategy::MinEscape {
        let single_count = payload.iter().filter(|&&b| b == b'\'').count();
        let double_count = payload.iter().filter(|&&b| b == b'"').count();
        if single_count < double_count {
            return '\'';
        }
        if double_count < single_count {
            return '"';
        }
        return pref as char;
    }

    let mut has_pref = false;
    for &b in payload {
        if b == other {
            return pref as char;
        }
        if b == pref {
            has_pref = true;
        }
    }
    if has_pref {
        other as char
    } else {
        pref as char
    }
}

fn is_printable(r: u32) -> bool {
    static BITSET: &[u8] = include_bytes!("unicode_printable.bin");
    if r >= 0x110000 {
        return false;
    }
    let idx = (r / 8) as usize;
    let bit = 1u8 << (r % 8);
    BITSET[idx] & bit != 0
}

pub fn escape_rune(r: u32, is_string: bool, quote: Option<char>) -> String {
    let q = quote.map(|c| c as u32).unwrap_or(u32::MAX);
    if r == b'\\' as u32 || (quote.is_some() && r == q) {
        return format!("\\{}", char::from_u32(r).unwrap_or('\u{fffd}'));
    }
    if is_string && r == b'\n' as u32 {
        return "\\n".to_string();
    }
    if is_string && r == b'\r' as u32 {
        return "\\r".to_string();
    }
    if is_string && r == b'\t' as u32 {
        return "\\t".to_string();
    }
    if is_string && is_printable(r) {
        return char::from_u32(r).unwrap_or('\u{fffd}').to_string();
    }
    if (0x20..=0x7E).contains(&r) {
        return char::from_u32(r).unwrap_or('\u{fffd}').to_string();
    }
    if r < 0x100 {
        return format!("\\x{r:02x}");
    }
    if r > 0xFFFF {
        return format!("\\U{r:08x}");
    }
    format!("\\u{r:04x}")
}

pub fn to_string_literal(s: &str, cfg: LiteralQuoteConfig) -> String {
    let quote = quote_char_for_payload(s.as_bytes(), cfg);
    let mut out = String::with_capacity(s.len() + 2);
    out.push(quote);
    for ch in s.chars() {
        out.push_str(&escape_rune(ch as u32, true, Some(quote)));
    }
    out.push(quote);
    out
}

pub fn to_bytes_literal(data: &[u8], cfg: LiteralQuoteConfig) -> String {
    let quote = quote_char_for_payload(data, cfg);
    let mut out = String::with_capacity(data.len() + 3);
    out.push('b');
    out.push(quote);
    for &b in data {
        out.push_str(&escape_rune(u32::from(b), false, Some(quote)));
    }
    out.push(quote);
    out
}

pub fn sql_cast_quoted(payload: &str, cast_type: &str, cfg: LiteralQuoteConfig) -> String {
    let lit = to_string_literal(payload, cfg);
    format!("CAST({lit} AS {cast_type})")
}
