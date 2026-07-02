//! Encoder round-trip tests derived from conformance value_cases.

use serde::Deserialize;
use serde_json::Value as JsonValue;
use spanvalue::{
    encode_value, format_result_row, format_value, simple_format_config, wire_to_native,
    Field, NativeValue, StructType, Type, Value,
};

const CONFORMANCE_JSON: &str = include_str!("../../testdata/conformance.json");

#[derive(Debug, Deserialize)]
struct ConformanceFile {
    value_cases: Vec<ValueCase>,
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
        .and_then(|v| v.as_str())
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
        .and_then(|v| v.as_str())
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
        if v.is_null() || v.as_str() == Some("NULL_VALUE") {
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

fn wire_equal(a: &Value, b: &Value) -> bool {
    match (a, b) {
        (Value::Null | Value::Missing, Value::Null | Value::Missing) => true,
        (Value::Bool(x), Value::Bool(y)) => x == y,
        (Value::Number(x), Value::Number(y)) => {
            // Accept int vs float wire forms (e.g. 0 vs 0.0).
            if x.to_bits() == y.to_bits() {
                return true;
            }
            x == y
        }
        (Value::String(x), Value::String(y)) => x == y,
        (Value::List(xs), Value::List(ys)) => {
            xs.len() == ys.len() && xs.iter().zip(ys).all(|(l, r)| wire_equal(l, r))
        }
        _ => false,
    }
}

#[test]
fn encoder_round_trip_value_cases() {
    let data: ConformanceFile =
        serde_json::from_str(CONFORMANCE_JSON).expect("parse conformance.json");
    let config = simple_format_config("<null>").unwrap();
    let mut failed = 0usize;
    for case in &data.value_cases {
        let typ = parse_type_json(&case.typ);
        let wire = parse_value_json(&case.value);
        let native = wire_to_native(&typ, &wire).expect("wire_to_native");
        let encoded = encode_value(&typ, &native).expect("encode_value");
        if !wire_equal(&encoded, &wire) {
            failed += 1;
            eprintln!(
                "encoder case {:?}: encoded {:?} want {:?}",
                case.name, encoded, wire
            );
            continue;
        }
        let formatted = format_value(&typ, &encoded, &config, true).unwrap();
        if formatted != case.expected.simple {
            failed += 1;
            eprintln!(
                "encoder format {:?}: got {:?} want {:?}",
                case.name, formatted, case.expected.simple
            );
        }
    }
    assert_eq!(failed, 0, "{failed} encoder round-trip failures");
}

#[test]
fn format_result_row_encoder_smoke() {
    let int_typ = spanvalue::type_from_parts(Some("INT64"), None, None, None, None);
    let str_typ = spanvalue::type_from_parts(Some("STRING"), None, None, None, None);
    let bool_typ = spanvalue::type_from_parts(Some("BOOL"), None, None, None, None);
    let config = simple_format_config("<null>").unwrap();
    let got = format_result_row(
        &[bool_typ, int_typ, str_typ],
        &[
            NativeValue::Bool(true),
            NativeValue::Null,
            NativeValue::Str("ok".into()),
        ],
        &config,
    )
    .unwrap();
    assert_eq!(got, vec!["true", "<null>", "ok"]);
}
