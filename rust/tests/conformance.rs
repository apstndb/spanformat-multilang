//! Conformance tests against shared testdata.

use serde::Deserialize;
use serde_json::Value as JsonValue;
use spanvalue::{
    format_type, format_type_verbose_annotation_omit, format_type_verbose_annotation_primary,
    format_value, literal_format_config, simple_format_config, spanner_cli_format_config,
    Field, FormatOption, LiteralQuoteConfig, PreferredQuote, QuoteStrategy, StructType, Type, Value,
    FORMAT_OPTION_MORE_VERBOSE, FORMAT_OPTION_NORMAL, FORMAT_OPTION_SIMPLE,
    FORMAT_OPTION_SIMPLEST, FORMAT_OPTION_VERBOSE,
};

const CONFORMANCE_JSON: &str = include_str!("../../testdata/conformance.json");

#[derive(Debug, Deserialize)]
struct ConformanceFile {
    #[allow(dead_code)]
    spec_version: String,
    type_cases: Vec<TypeCase>,
    value_cases: Vec<ValueCase>,
}

#[derive(Debug, Deserialize)]
struct TypeCase {
    name: String,
    #[serde(rename = "type")]
    typ: JsonValue,
    expected: TypeExpected,
}

#[derive(Debug, Deserialize)]
struct TypeExpected {
    simplest: String,
    simple: String,
    normal: String,
    verbose: String,
    more_verbose: String,
    verbose_annotation_omit: String,
    verbose_annotation_primary: String,
}

#[derive(Debug, Deserialize)]
struct ValueCase {
    name: String,
    #[serde(rename = "type")]
    typ: JsonValue,
    value: JsonValue,
    expected: ValueExpected,
}

#[derive(Debug, Deserialize)]
struct ValueExpected {
    simple: String,
    literal: String,
    spanner_cli: String,
    literal_quotes: LiteralQuotesExpected,
}

#[derive(Debug, Deserialize)]
struct LiteralQuotesExpected {
    legacy_double: String,
    legacy_single: String,
    always_double: String,
    always_single: String,
    min_escape_double: String,
    min_escape_single: String,
}

fn json_get<'a>(obj: &'a JsonValue, keys: &[&str]) -> Option<&'a JsonValue> {
    let JsonValue::Object(map) = obj else {
        return None;
    };
    for key in keys {
        if let Some(v) = map.get(*key) {
            return Some(v);
        }
    }
    None
}

fn json_str(value: &JsonValue) -> Option<&str> {
    value.as_str()
}

fn json_code(value: &JsonValue) -> i32 {
    if let Some(s) = value.as_str() {
        return spanvalue::parse_type_code(Some(s));
    }
    if let Some(n) = value.as_i64() {
        return n as i32;
    }
    0
}

fn json_annotation(value: &JsonValue) -> i32 {
    if let Some(s) = value.as_str() {
        return spanvalue::parse_type_annotation(Some(s));
    }
    if let Some(n) = value.as_i64() {
        return n as i32;
    }
    0
}

fn parse_type_json(value: &JsonValue) -> Type {
    let code = json_get(value, &["code", "Code"]).map(json_code).unwrap_or(0);
    let type_annotation = json_get(
        value,
        &["type_annotation", "typeAnnotation", "TypeAnnotation"],
    )
    .map(json_annotation)
    .unwrap_or(0);
    let proto_type_fqn = json_get(value, &["proto_type_fqn", "protoTypeFqn", "ProtoTypeFqn"])
        .and_then(json_str)
        .unwrap_or("")
        .to_string();
    let array_element_type = json_get(
        value,
        &["array_element_type", "arrayElementType", "ArrayElementType"],
    )
    .map(parse_type_json);
    let struct_type = json_get(value, &["struct_type", "structType", "StructType"])
        .map(parse_struct_type_json);
    Type {
        code,
        array_element_type: array_element_type.map(Box::new),
        struct_type,
        proto_type_fqn,
        type_annotation,
    }
}

fn parse_struct_type_json(value: &JsonValue) -> StructType {
    let fields = json_get(value, &["fields", "Fields"])
        .and_then(|v| v.as_array())
        .map(|arr| arr.iter().map(parse_field_json).collect())
        .unwrap_or_default();
    StructType { fields }
}

fn parse_field_json(value: &JsonValue) -> Field {
    let name = json_get(value, &["name", "Name"])
        .and_then(json_str)
        .unwrap_or("")
        .to_string();
    let field_type = json_get(value, &["type", "Type"])
        .map(parse_type_json)
        .unwrap_or_default();
    Field { name, field_type }
}

fn parse_value_json(value: &JsonValue) -> Value {
    if value.is_null() {
        return Value::Null;
    }
    if let Some(v) = json_get(value, &["null_value", "nullValue"]) {
        if v.is_null() || json_str(v) == Some("NULL_VALUE") {
            return Value::Null;
        }
    }
    if let Some(v) = json_get(value, &["bool_value", "boolValue"]) {
        return Value::Bool(v.as_bool().unwrap_or(false));
    }
    if let Some(v) = json_get(value, &["number_value", "numberValue"]) {
        return Value::Number(v.as_f64().unwrap_or(0.0));
    }
    if let Some(v) = json_get(value, &["string_value", "stringValue"]) {
        return Value::String(v.as_str().unwrap_or("").to_string());
    }
    if let Some(v) = json_get(value, &["list_value", "listValue"]) {
        let values = json_get(v, &["values", "Values"])
            .and_then(|vv| vv.as_array())
            .map(|arr| arr.iter().map(parse_value_json).collect())
            .unwrap_or_default();
        return Value::List(values);
    }
    match value {
        JsonValue::Bool(b) => Value::Bool(*b),
        JsonValue::Number(n) => Value::Number(n.as_f64().unwrap_or(0.0)),
        JsonValue::String(s) => Value::String(s.clone()),
        JsonValue::Array(arr) => Value::List(arr.iter().map(parse_value_json).collect()),
        JsonValue::Object(map) if map.is_empty() => Value::Missing,
        _ => Value::Missing,
    }
}

fn load_conformance() -> ConformanceFile {
    serde_json::from_str(CONFORMANCE_JSON).expect("parse conformance.json")
}

fn type_preset_option(preset: &str) -> Option<FormatOption> {
    match preset {
        "simplest" => Some(FORMAT_OPTION_SIMPLEST),
        "simple" => Some(FORMAT_OPTION_SIMPLE),
        "normal" => Some(FORMAT_OPTION_NORMAL),
        "verbose" => Some(FORMAT_OPTION_VERBOSE),
        "more_verbose" => Some(FORMAT_OPTION_MORE_VERBOSE),
        _ => None,
    }
}

fn format_type_preset(preset: &str, typ: &Type) -> String {
    match preset {
        "verbose_annotation_omit" => format_type_verbose_annotation_omit(typ).unwrap(),
        "verbose_annotation_primary" => format_type_verbose_annotation_primary(typ).unwrap(),
        other => format_type(typ, type_preset_option(other)).unwrap(),
    }
}

fn quote_policy(name: &str) -> LiteralQuoteConfig {
    match name {
        "legacy_single" => LiteralQuoteConfig {
            strategy: QuoteStrategy::Legacy,
            preferred_quote: PreferredQuote::Single,
        },
        "always_double" => LiteralQuoteConfig {
            strategy: QuoteStrategy::Always,
            preferred_quote: PreferredQuote::Double,
        },
        "always_single" => LiteralQuoteConfig {
            strategy: QuoteStrategy::Always,
            preferred_quote: PreferredQuote::Single,
        },
        "min_escape_double" => LiteralQuoteConfig {
            strategy: QuoteStrategy::MinEscape,
            preferred_quote: PreferredQuote::Double,
        },
        "min_escape_single" => LiteralQuoteConfig {
            strategy: QuoteStrategy::MinEscape,
            preferred_quote: PreferredQuote::Single,
        },
        _ => LiteralQuoteConfig::default(),
    }
}

#[test]
fn type_cases_all_presets() {
    let data = load_conformance();
    let presets = [
        "simplest",
        "simple",
        "normal",
        "verbose",
        "more_verbose",
        "verbose_annotation_omit",
        "verbose_annotation_primary",
    ];
    let mut passed = 0usize;
    let mut failed = 0usize;
    for preset in presets {
        for case in &data.type_cases {
            let typ = parse_type_json(&case.typ);
            let got = format_type_preset(preset, &typ);
            let want = match preset {
                "simplest" => &case.expected.simplest,
                "simple" => &case.expected.simple,
                "normal" => &case.expected.normal,
                "verbose" => &case.expected.verbose,
                "more_verbose" => &case.expected.more_verbose,
                "verbose_annotation_omit" => &case.expected.verbose_annotation_omit,
                "verbose_annotation_primary" => &case.expected.verbose_annotation_primary,
                _ => unreachable!(),
            };
            if got == *want {
                passed += 1;
            } else {
                failed += 1;
                eprintln!(
                    "type case {:?} preset {:?}: got {:?} want {:?}",
                    case.name, preset, got, want
                );
            }
        }
    }
    eprintln!("type_cases: passed={passed} failed={failed}");
    assert_eq!(failed, 0, "{failed} type case failures");
}

#[test]
fn value_cases_presets() {
    let data = load_conformance();
    let presets = ["simple", "literal", "spanner_cli"];
    let mut passed = 0usize;
    let mut failed = 0usize;
    for preset in presets {
        let config = match preset {
            "simple" => simple_format_config("<null>").unwrap(),
            "literal" => literal_format_config(None, "NULL").unwrap(),
            "spanner_cli" => spanner_cli_format_config("NULL").unwrap(),
            _ => unreachable!(),
        };
        for case in &data.value_cases {
            let typ = parse_type_json(&case.typ);
            let value = parse_value_json(&case.value);
            let got = format_value(&typ, &value, &config, true).unwrap();
            let want = match preset {
                "simple" => &case.expected.simple,
                "literal" => &case.expected.literal,
                "spanner_cli" => &case.expected.spanner_cli,
                _ => unreachable!(),
            };
            if got == *want {
                passed += 1;
            } else {
                failed += 1;
                eprintln!(
                    "value case {:?} preset {:?}: got {:?} want {:?}",
                    case.name, preset, got, want
                );
            }
        }
    }
    eprintln!("value_cases: passed={passed} failed={failed}");
    assert_eq!(failed, 0, "{failed} value case failures");
}

#[test]
fn value_cases_literal_quotes() {
    let data = load_conformance();
    let policies = [
        "legacy_double",
        "legacy_single",
        "always_double",
        "always_single",
        "min_escape_double",
        "min_escape_single",
    ];
    let mut passed = 0usize;
    let mut failed = 0usize;
    for policy_name in policies {
        let config = literal_format_config(Some(quote_policy(policy_name)), "NULL").unwrap();
        for case in &data.value_cases {
            let typ = parse_type_json(&case.typ);
            let value = parse_value_json(&case.value);
            let got = format_value(&typ, &value, &config, true).unwrap();
            let want = match policy_name {
                "legacy_double" => &case.expected.literal_quotes.legacy_double,
                "legacy_single" => &case.expected.literal_quotes.legacy_single,
                "always_double" => &case.expected.literal_quotes.always_double,
                "always_single" => &case.expected.literal_quotes.always_single,
                "min_escape_double" => &case.expected.literal_quotes.min_escape_double,
                "min_escape_single" => &case.expected.literal_quotes.min_escape_single,
                _ => unreachable!(),
            };
            if got == *want {
                passed += 1;
            } else {
                failed += 1;
                eprintln!(
                    "value case {:?} quote {:?}: got {:?} want {:?}",
                    case.name, policy_name, got, want
                );
            }
        }
    }
    eprintln!("literal_quotes: passed={passed} failed={failed}");
    assert_eq!(failed, 0, "{failed} literal quote failures");
}
