//! FormatConfig presets and value formatting.

use crate::bytes_fmt::{decode_base64_wire, readable_string_from_base64_wire};
use crate::codes::TypeCode;
use crate::errors::{
    EmptyNullStringError, EmptyTypeFQNError, FormatError, MalformedWireError,
    MismatchedFieldsError, Result, UnexpectedComplexValueKindError, UnknownTypeError,
};
use crate::float_fmt::{
    float32_to_literal, float64_to_literal, format_go_g, format_spanner_cli_float, narrow_float32,
};
use crate::quote::{
    normalize_literal_quote, sql_cast_quoted, to_bytes_literal, to_string_literal,
    LiteralQuoteConfig,
};
use crate::type_format::format_type_verbose;
use crate::types::{
    array_element_type, bool_value, field_name, field_type, is_null_value, list_values,
    number_value, proto_type_fqn, string_value, struct_fields, type_code, value_kind, Type,
    Value, ValueKind,
};

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum Preset {
    Simple = 0,
    Literal = 1,
    SpannerCli = 2,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct FormatConfig {
    pub preset: Preset,
    pub null_string: String,
    pub quote: LiteralQuoteConfig,
}

impl FormatConfig {
    pub fn new(preset: Preset, null_string: impl Into<String>, quote: LiteralQuoteConfig) -> Result<Self> {
        let null_string = null_string.into();
        if null_string.is_empty() {
            return Err(FormatError::EmptyNullString(EmptyNullStringError::new(
                "null_string must not be empty",
            )));
        }
        Ok(Self {
            preset,
            null_string,
            quote: normalize_literal_quote(quote),
        })
    }

    pub fn with_null_string(&self, null_string: impl Into<String>) -> Result<Self> {
        Self::new(self.preset, null_string, self.quote)
    }
}

pub fn simple_format_config(null_string: &str) -> Result<FormatConfig> {
    FormatConfig::new(Preset::Simple, null_string, LiteralQuoteConfig::default())
}

pub fn literal_format_config(
    quote: Option<LiteralQuoteConfig>,
    null_string: &str,
) -> Result<FormatConfig> {
    FormatConfig::new(
        Preset::Literal,
        null_string,
        quote.unwrap_or_default(),
    )
}

pub fn spanner_cli_format_config(null_string: &str) -> Result<FormatConfig> {
    FormatConfig::new(Preset::SpannerCli, null_string, LiteralQuoteConfig::default())
}

fn is_complex_type(code: i32) -> bool {
    code == TypeCode::Array as i32 || code == TypeCode::Struct as i32
}

fn is_scalar_type(code: i32) -> bool {
    matches!(
        code,
        c if c == TypeCode::Bool as i32
            || c == TypeCode::Int64 as i32
            || c == TypeCode::Enum as i32
            || c == TypeCode::Float32 as i32
            || c == TypeCode::Float64 as i32
            || c == TypeCode::String as i32
            || c == TypeCode::Bytes as i32
            || c == TypeCode::Proto as i32
            || c == TypeCode::Timestamp as i32
            || c == TypeCode::Date as i32
            || c == TypeCode::Numeric as i32
            || c == TypeCode::Json as i32
            || c == TypeCode::Interval as i32
            || c == TypeCode::Uuid as i32
    )
}

fn format_type_code_name(code: i32) -> Result<String> {
    crate::type_format::format_type_code(code, crate::type_format::UnknownMode::Verbose)
}

fn require_string_wire(value: &Value, code: i32) -> Result<()> {
    if value_kind(value) != ValueKind::String {
        return Err(FormatError::MalformedWire(MalformedWireError::new(format!(
            "{} value kind {:?}",
            format_type_code_name(code)?,
            value_kind(value)
        ))));
    }
    Ok(())
}

fn require_bool_wire(value: &Value, code: i32) -> Result<()> {
    if value_kind(value) != ValueKind::Bool {
        return Err(FormatError::MalformedWire(MalformedWireError::new(format!(
            "{} value kind {:?}",
            format_type_code_name(code)?,
            value_kind(value)
        ))));
    }
    Ok(())
}

fn validate_float_wire(value: &Value, code: i32) -> Result<()> {
    match value_kind(value) {
        ValueKind::Number => Ok(()),
        ValueKind::String => {
            let s = string_value(value).unwrap_or("");
            if matches!(s, "NaN" | "Infinity" | "-Infinity") {
                Ok(())
            } else {
                Err(FormatError::MalformedWire(MalformedWireError::new(format!(
                    "{} unexpected float string {s:?}",
                    format_type_code_name(code)?
                ))))
            }
        }
        kind => Err(FormatError::MalformedWire(MalformedWireError::new(format!(
            "{} value kind {kind:?}",
            format_type_code_name(code)?
        )))),
    }
}

fn gcv_float64(value: &Value) -> Result<f64> {
    match value_kind(value) {
        ValueKind::Number => number_value(value).ok_or_else(|| {
            FormatError::MalformedWire(MalformedWireError::new("FLOAT64 missing number"))
        }),
        ValueKind::String => {
            let s = string_value(value).unwrap_or("");
            match s {
                "NaN" => Ok(f64::NAN),
                "Infinity" => Ok(f64::INFINITY),
                "-Infinity" => Ok(f64::NEG_INFINITY),
                _ => Err(FormatError::MalformedWire(MalformedWireError::new(format!(
                    "FLOAT64 unexpected float string {s:?}"
                )))),
            }
        }
        kind => Err(FormatError::MalformedWire(MalformedWireError::new(format!(
            "FLOAT64 value kind {kind:?}"
        )))),
    }
}

fn gcv_float32(value: &Value) -> Result<f64> {
    Ok(f64::from(narrow_float32(gcv_float64(value)?)))
}

fn validate_scalar_wire(typ: &Type, value: &Value) -> Result<()> {
    if is_null_value(value) {
        return Err(FormatError::MalformedWire(MalformedWireError::new(format!(
            "{} unexpected null value",
            format_type_code_name(type_code(typ))?
        ))));
    }
    let code = type_code(typ);
    match code {
        c if c == TypeCode::Bool as i32 => require_bool_wire(value, code),
        c if matches!(
            c,
            x if x == TypeCode::Int64 as i32
                || x == TypeCode::Enum as i32
                || x == TypeCode::String as i32
                || x == TypeCode::Bytes as i32
                || x == TypeCode::Proto as i32
                || x == TypeCode::Timestamp as i32
                || x == TypeCode::Date as i32
                || x == TypeCode::Numeric as i32
                || x == TypeCode::Interval as i32
                || x == TypeCode::Uuid as i32
                || x == TypeCode::Json as i32
        ) => require_string_wire(value, code),
        c if c == TypeCode::Float32 as i32 || c == TypeCode::Float64 as i32 => {
            validate_float_wire(value, code)
        }
        c if c == TypeCode::TypeCodeUnspecified as i32 => {
            Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}"))))
        }
        c if !is_scalar_type(c) => {
            Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}"))))
        }
        _ => Ok(()),
    }
}

fn trim_spanner_cli_numeric_fraction(s: &str) -> String {
    if !s.contains('.') {
        return s.to_string();
    }
    s.trim_end_matches('0').trim_end_matches('.').to_string()
}

fn numeric_wire_string(value: &Value) -> Result<&str> {
    string_value(value).ok_or_else(|| {
        FormatError::MalformedWire(MalformedWireError::new("NUMERIC missing string"))
    })
}

fn string_based_literal(type_name: &str, payload: &str, quote: LiteralQuoteConfig) -> String {
    format!("{type_name} {}", to_string_literal(payload, quote))
}

fn format_scalar_simple(typ: &Type, value: &Value) -> Result<String> {
    validate_scalar_wire(typ, value)?;
    let code = type_code(typ);
    match code {
        c if c == TypeCode::Bool as i32 => Ok(if bool_value(value).unwrap_or(false) {
            "true".to_string()
        } else {
            "false".to_string()
        }),
        c if matches!(
            c,
            x if x == TypeCode::Int64 as i32
                || x == TypeCode::Enum as i32
                || x == TypeCode::String as i32
                || x == TypeCode::Timestamp as i32
                || x == TypeCode::Date as i32
                || x == TypeCode::Json as i32
                || x == TypeCode::Interval as i32
                || x == TypeCode::Uuid as i32
        ) => Ok(string_value(value).unwrap_or("").to_string()),
        c if c == TypeCode::Float32 as i32 => Ok(format_go_g(gcv_float32(value)?, 32)),
        c if c == TypeCode::Float64 as i32 => Ok(format_go_g(gcv_float64(value)?, 64)),
        c if c == TypeCode::Bytes as i32 || c == TypeCode::Proto as i32 => {
            readable_string_from_base64_wire(string_value(value).unwrap_or(""))
        }
        c if c == TypeCode::Numeric as i32 => Ok(numeric_wire_string(value)?.to_string()),
        _ => Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}")))),
    }
}

fn parse_int64_wire(s: &str) -> Result<()> {
    let n: i128 = s.parse().map_err(|_| {
        FormatError::MalformedWire(MalformedWireError::new(format!("invalid INT64 wire {s:?}")))
    })?;
    if n < -(1i128 << 63) || n > (1i128 << 63) - 1 {
        return Err(FormatError::MalformedWire(MalformedWireError::new(format!(
            "INT64 out of range {s:?}"
        ))));
    }
    Ok(())
}

fn format_scalar_literal(typ: &Type, value: &Value, quote: LiteralQuoteConfig) -> Result<String> {
    validate_scalar_wire(typ, value)?;
    let code = type_code(typ);
    match code {
        c if c == TypeCode::Bool as i32 => Ok(if bool_value(value).unwrap_or(false) {
            "true".to_string()
        } else {
            "false".to_string()
        }),
        c if c == TypeCode::Int64 as i32 => {
            let s = string_value(value).unwrap_or("");
            parse_int64_wire(s)?;
            Ok(s.to_string())
        }
        c if c == TypeCode::Float32 as i32 => Ok(float32_to_literal(gcv_float32(value)?, quote)),
        c if c == TypeCode::Float64 as i32 => Ok(float64_to_literal(gcv_float64(value)?, quote)),
        c if c == TypeCode::String as i32 => {
            Ok(to_string_literal(string_value(value).unwrap_or(""), quote))
        }
        c if c == TypeCode::Bytes as i32 || c == TypeCode::Proto as i32 => {
            let data = decode_base64_wire(string_value(value).unwrap_or(""))?;
            Ok(to_bytes_literal(&data, quote))
        }
        c if c == TypeCode::Timestamp as i32 => Ok(string_based_literal(
            "TIMESTAMP",
            string_value(value).unwrap_or(""),
            quote,
        )),
        c if c == TypeCode::Date as i32 => Ok(string_based_literal(
            "DATE",
            string_value(value).unwrap_or(""),
            quote,
        )),
        c if c == TypeCode::Numeric as i32 => Ok(string_based_literal(
            "NUMERIC",
            numeric_wire_string(value)?,
            quote,
        )),
        c if c == TypeCode::Json as i32 => Ok(string_based_literal(
            "JSON",
            string_value(value).unwrap_or(""),
            quote,
        )),
        c if c == TypeCode::Interval as i32 => Ok(sql_cast_quoted(
            string_value(value).unwrap_or(""),
            "INTERVAL",
            quote,
        )),
        c if c == TypeCode::Uuid as i32 => Ok(sql_cast_quoted(
            string_value(value).unwrap_or(""),
            "UUID",
            quote,
        )),
        _ => Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}")))),
    }
}

fn format_scalar_spanner_cli(typ: &Type, value: &Value) -> Result<String> {
    validate_scalar_wire(typ, value)?;
    let code = type_code(typ);
    match code {
        c if c == TypeCode::Bool as i32 => Ok(if bool_value(value).unwrap_or(false) {
            "true".to_string()
        } else {
            "false".to_string()
        }),
        c if matches!(
            c,
            x if x == TypeCode::Int64 as i32
                || x == TypeCode::Enum as i32
                || x == TypeCode::String as i32
                || x == TypeCode::Bytes as i32
                || x == TypeCode::Proto as i32
                || x == TypeCode::Timestamp as i32
                || x == TypeCode::Date as i32
                || x == TypeCode::Interval as i32
                || x == TypeCode::Uuid as i32
                || x == TypeCode::Json as i32
        ) => Ok(string_value(value).unwrap_or("").to_string()),
        c if c == TypeCode::Float32 as i32 => Ok(format_spanner_cli_float(gcv_float32(value)?, 32)),
        c if c == TypeCode::Float64 as i32 => Ok(format_spanner_cli_float(gcv_float64(value)?, 64)),
        c if c == TypeCode::Numeric as i32 => {
            Ok(trim_spanner_cli_numeric_fraction(numeric_wire_string(value)?))
        }
        _ => Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}")))),
    }
}

fn format_proto_literal(
    typ: &Type,
    value: &Value,
    quote: LiteralQuoteConfig,
    null_string: &str,
) -> Result<String> {
    if type_code(typ) != TypeCode::Proto as i32 {
        return Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}"))));
    }
    if is_null_value(value) {
        return Ok(null_string.to_string());
    }
    require_string_wire(value, TypeCode::Proto as i32)?;
    let data = decode_base64_wire(string_value(value).unwrap_or(""))?;
    let fqn = proto_type_fqn(typ);
    if fqn.is_empty() {
        return Err(FormatError::EmptyTypeFQN(EmptyTypeFQNError::new(
            "empty type FQN for PROTO",
        )));
    }
    Ok(format!(
        "CAST({} AS `{fqn}`)",
        to_bytes_literal(&data, quote)
    ))
}

fn format_enum_literal(typ: &Type, value: &Value, null_string: &str) -> Result<String> {
    if type_code(typ) != TypeCode::Enum as i32 {
        return Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}"))));
    }
    if is_null_value(value) {
        return Ok(null_string.to_string());
    }
    require_string_wire(value, TypeCode::Enum as i32)?;
    let s = string_value(value).unwrap_or("");
    s.parse::<i64>().map_err(|_| {
        FormatError::MalformedWire(MalformedWireError::new(format!(
            "failed to parse enum wire payload {s:?}"
        )))
    })?;
    let fqn = proto_type_fqn(typ);
    if fqn.is_empty() {
        return Err(FormatError::EmptyTypeFQN(EmptyTypeFQNError::new(
            "empty type FQN for ENUM",
        )));
    }
    Ok(format!("CAST({s} AS `{fqn}`)"))
}

fn get_list_value(_typ: &Type, value: &Value, expected_code: i32) -> Result<Vec<Value>> {
    if value_kind(value) != ValueKind::List {
        return Err(FormatError::UnexpectedComplexValueKind(
            UnexpectedComplexValueKindError::new(format!(
                "unexpected complex value kind for {}: {:?}",
                format_type_code_name(expected_code)?,
                value_kind(value)
            )),
        ));
    }
    Ok(list_values(value).unwrap_or(&[]).to_vec())
}

pub fn format_value(
    typ: &Type,
    value: &Value,
    config: &FormatConfig,
    toplevel: bool,
) -> Result<String> {
    if is_null_value(value) {
        return Ok(config.null_string.clone());
    }

    let code = type_code(typ);

    if code == TypeCode::Array as i32 {
        let elems = get_list_value(typ, value, code)?;
        let elem_type = array_element_type(typ).ok_or_else(|| {
            FormatError::UnknownType(UnknownTypeError::new("ARRAY missing element type"))
        })?;
        let parts: Result<Vec<_>> = elems
            .iter()
            .map(|elem| format_value(elem_type, elem, config, false))
            .collect();
        let joined = parts?.join(", ");
        if config.preset == Preset::Literal
            && toplevel
            && is_complex_type(type_code(elem_type))
        {
            return Ok(format!(
                "{}[{joined}]",
                format_type_verbose(typ)?
            ));
        }
        return Ok(format!("[{joined}]"));
    }

    if code == TypeCode::Struct as i32 {
        let field_vals = get_list_value(typ, value, code)?;
        let fields = struct_fields(typ);
        if field_vals.len() != fields.len() {
            return Err(FormatError::MismatchedFields(MismatchedFieldsError::new(format!(
                "got {} values, want {}",
                field_vals.len(),
                fields.len()
            ))));
        }
        if config.preset == Preset::Simple {
            let mut field_strs = Vec::with_capacity(fields.len());
            for (field, val) in fields.iter().zip(field_vals.iter()) {
                let rendered = format_value(field_type(field), val, config, false)?;
                let name = field_name(field);
                if name.is_empty() {
                    field_strs.push(rendered);
                } else {
                    field_strs.push(format!("{rendered} AS {name}"));
                }
            }
            return Ok(format!("({})", field_strs.join(", ")));
        }
        let field_strs: Result<Vec<_>> = fields
            .iter()
            .zip(field_vals.iter())
            .map(|(field, val)| format_value(field_type(field), val, config, false))
            .collect();
        let inner = field_strs?.join(", ");
        return match config.preset {
            Preset::Literal => {
                let prefix = if toplevel {
                    format_type_verbose(typ)?
                } else {
                    String::new()
                };
                Ok(format!("{prefix}({inner})"))
            }
            Preset::SpannerCli => Ok(format!("[{inner}]")),
            Preset::Simple => Ok(format!("({inner})")),
        };
    }

    if code == TypeCode::Proto as i32 {
        if config.preset == Preset::Literal {
            return format_proto_literal(typ, value, config.quote, &config.null_string);
        }
        require_string_wire(value, code)?;
        if config.preset == Preset::SpannerCli {
            return Ok(string_value(value).unwrap_or("").to_string());
        }
        return readable_string_from_base64_wire(string_value(value).unwrap_or(""));
    }

    if code == TypeCode::Enum as i32 {
        if config.preset == Preset::Literal {
            return format_enum_literal(typ, value, &config.null_string);
        }
        return format_scalar_simple(typ, value);
    }

    if code == TypeCode::TypeCodeUnspecified as i32 || !is_scalar_type(code) {
        return Err(FormatError::UnknownType(UnknownTypeError::new(format!("{typ:?}"))));
    }

    match config.preset {
        Preset::Simple => format_scalar_simple(typ, value),
        Preset::Literal => format_scalar_literal(typ, value, config.quote),
        Preset::SpannerCli => format_scalar_spanner_cli(typ, value),
    }
}

pub fn format_row(types: &[Type], values: &[Value], config: &FormatConfig) -> Result<Vec<String>> {
    if types.len() != values.len() {
        return Err(FormatError::RowLengthMismatch(format!(
            "len(types)={} != len(values)={}",
            types.len(),
            values.len()
        )));
    }
    types
        .iter()
        .zip(values.iter())
        .map(|(t, v)| format_value(t, v, config, true))
        .collect()
}
