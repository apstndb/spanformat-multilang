//! Parse protojson-shaped `Type` and `Value` objects (shared conformance format).

use crate::codes::{parse_type_annotation, parse_type_code};
use crate::types::{Field, StructType, Type, Value};
use crate::Result;
use serde_json::Value as JsonValue;

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
        return parse_type_code(Some(s));
    }
    if let Some(n) = value.as_i64() {
        return n as i32;
    }
    0
}

fn json_annotation(value: &JsonValue) -> i32 {
    if let Some(s) = value.as_str() {
        return parse_type_annotation(Some(s));
    }
    if let Some(n) = value.as_i64() {
        return n as i32;
    }
    0
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
        .map(type_from_protojson)
        .unwrap_or_default();
    Field { name, field_type }
}

/// Parse a protojson `google.spanner.v1.Type` object (snake_case or camelCase keys).
pub fn type_from_protojson(value: &JsonValue) -> Type {
    let code = json_get(value, &["code", "Code"])
        .map(json_code)
        .unwrap_or(0);
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
    .map(type_from_protojson);
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

/// Parse a protojson `google.protobuf.Value` object (snake_case or camelCase keys).
pub fn value_from_protojson(value: &JsonValue) -> Result<Value> {
    if value.is_null() {
        return Ok(Value::Null);
    }
    if let Some(v) = json_get(value, &["null_value", "nullValue"]) {
        if v.is_null() || v.as_str() == Some("NULL_VALUE") {
            return Ok(Value::Null);
        }
    }
    if let Some(v) = json_get(value, &["bool_value", "boolValue"]) {
        return Ok(Value::Bool(v.as_bool().unwrap_or(false)));
    }
    if let Some(v) = json_get(value, &["number_value", "numberValue"]) {
        return Ok(Value::Number(v.as_f64().unwrap_or(0.0)));
    }
    if let Some(v) = json_get(value, &["string_value", "stringValue"]) {
        return Ok(Value::String(v.as_str().unwrap_or("").to_string()));
    }
    if let Some(v) = json_get(value, &["list_value", "listValue"]) {
        let values = json_get(v, &["values", "Values"])
            .and_then(|v| v.as_array())
            .map(|arr| {
                arr.iter()
                    .map(value_from_protojson)
                    .collect::<Result<Vec<_>>>()
            })
            .transpose()?
            .unwrap_or_default();
        return Ok(Value::List(values));
    }
    if value.is_string() {
        return Ok(Value::String(value.as_str().unwrap_or("").to_string()));
    }
    if value.is_number() {
        return Ok(Value::Number(value.as_f64().unwrap_or(0.0)));
    }
    if value.is_boolean() {
        return Ok(Value::Bool(value.as_bool().unwrap_or(false)));
    }
    if let Some(arr) = value.as_array() {
        let values = arr
            .iter()
            .map(value_from_protojson)
            .collect::<Result<Vec<_>>>()?;
        return Ok(Value::List(values));
    }
    Ok(Value::Missing)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::codes::TypeCode;

    #[test]
    fn type_from_protojson_struct() {
        let json = serde_json::json!({
            "code": "STRUCT",
            "structType": {
                "fields": [
                    {"name": "n", "type": {"code": "INT64"}},
                    {"name": "s", "type": {"code": "STRING"}},
                ]
            }
        });
        let typ = type_from_protojson(&json);
        assert_eq!(typ.code, TypeCode::Struct as i32);
        let fields = typ.struct_type.expect("struct").fields;
        assert_eq!(fields.len(), 2);
        assert_eq!(fields[0].name, "n");
    }
}
