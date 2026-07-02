//! Wire encoders: native values → `google.protobuf.Value`.

use crate::bytes_fmt;
use crate::codes::TypeCode;
use crate::errors::{FormatError, Result};
use crate::format_config::{format_row, FormatConfig};
use crate::types::{array_element_type, struct_fields, field_type, Type, Value};

/// Native scalar/collection input for [`encode_value`].
#[derive(Clone, Debug, PartialEq)]
pub enum NativeValue {
    Null,
    Bool(bool),
    I64(i64),
    F64(f64),
    Str(String),
    Bytes(Vec<u8>),
    List(Vec<NativeValue>),
}

impl NativeValue {
    pub fn null() -> Self {
        Self::Null
    }
}

impl From<bool> for NativeValue {
    fn from(v: bool) -> Self {
        Self::Bool(v)
    }
}

impl From<i64> for NativeValue {
    fn from(v: i64) -> Self {
        Self::I64(v)
    }
}

impl From<i32> for NativeValue {
    fn from(v: i32) -> Self {
        Self::I64(i64::from(v))
    }
}

impl From<f64> for NativeValue {
    fn from(v: f64) -> Self {
        Self::F64(v)
    }
}

impl From<f32> for NativeValue {
    fn from(v: f32) -> Self {
        Self::F64(f64::from(v))
    }
}

impl From<String> for NativeValue {
    fn from(v: String) -> Self {
        Self::Str(v)
    }
}

impl From<&str> for NativeValue {
    fn from(v: &str) -> Self {
        Self::Str(v.to_string())
    }
}

impl From<Vec<u8>> for NativeValue {
    fn from(v: Vec<u8>) -> Self {
        Self::Bytes(v)
    }
}

impl From<Vec<NativeValue>> for NativeValue {
    fn from(v: Vec<NativeValue>) -> Self {
        Self::List(v)
    }
}

fn float64_to_wire(v: f64) -> Value {
    if v.is_nan() {
        return Value::String("NaN".into());
    }
    if v.is_infinite() {
        return Value::String(if v.is_sign_positive() {
            "Infinity".into()
        } else {
            "-Infinity".into()
        });
    }
    Value::Number(v)
}

fn encode_scalar(typ: &Type, native: &NativeValue) -> Result<Value> {
    let code = typ.code;
    match code {
        c if c == TypeCode::Bool as i32 => match native {
            NativeValue::Bool(v) => Ok(Value::Bool(*v)),
            _ => Err(native_type_mismatch(code, native)),
        },
        c if c == TypeCode::Int64 as i32 || c == TypeCode::Enum as i32 => match native {
            NativeValue::I64(v) => Ok(Value::String(v.to_string())),
            NativeValue::Str(v) => Ok(Value::String(v.clone())),
            _ => Err(native_type_mismatch(code, native)),
        },
        c if c == TypeCode::Float32 as i32 || c == TypeCode::Float64 as i32 => match native {
            NativeValue::F64(v) => Ok(float64_to_wire(*v)),
            _ => Err(native_type_mismatch(code, native)),
        },
        c if c == TypeCode::String as i32
            || c == TypeCode::Timestamp as i32
            || c == TypeCode::Date as i32
            || c == TypeCode::Numeric as i32
            || c == TypeCode::Json as i32
            || c == TypeCode::Interval as i32
            || c == TypeCode::Uuid as i32 =>
        {
            match native {
                NativeValue::Str(v) => Ok(Value::String(v.clone())),
                _ => Err(native_type_mismatch(code, native)),
            }
        }
        c if c == TypeCode::Bytes as i32 || c == TypeCode::Proto as i32 => match native {
            NativeValue::Bytes(v) => Ok(Value::String(bytes_fmt::encode_base64_wire(v.as_slice()))),
            NativeValue::Str(v) => Ok(Value::String(v.clone())),
            _ => Err(native_type_mismatch(code, native)),
        },
        _ => Err(FormatError::UnknownType(crate::errors::UnknownTypeError::new(format!(
            "unsupported scalar type code {code}"
        )))),
    }
}

fn native_type_mismatch(code: i32, native: &NativeValue) -> FormatError {
    FormatError::MalformedWire(crate::errors::MalformedWireError::new(format!(
        "native value {:?} does not match type code {code}",
        native
    )))
}

/// Encode a native value to wire `google.protobuf.Value` for `typ`.
pub fn encode_value(typ: &Type, native: &NativeValue) -> Result<Value> {
    if matches!(native, NativeValue::Null) {
        return Ok(Value::Null);
    }

    let code = typ.code;
    if code == TypeCode::Array as i32 {
        let elem_type = array_element_type(typ).ok_or_else(|| {
            FormatError::MalformedWire(crate::errors::MalformedWireError::new(
                "ARRAY missing array_element_type",
            ))
        })?;
        let NativeValue::List(elems) = native else {
            return Err(native_type_mismatch(code, native));
        };
        let mut values = Vec::with_capacity(elems.len());
        for elem in elems {
            values.push(encode_value(elem_type, elem)?);
        }
        return Ok(Value::List(values));
    }

    if code == TypeCode::Struct as i32 {
        let fields = struct_fields(typ);
        let NativeValue::List(elems) = native else {
            return Err(native_type_mismatch(code, native));
        };
        if elems.len() != fields.len() {
            return Err(FormatError::MismatchedFields(
                crate::errors::MismatchedFieldsError::new(format!(
                    "got {} native field values, want {}",
                    elems.len(),
                    fields.len()
                )),
            ));
        }
        let mut values = Vec::with_capacity(elems.len());
        for (field, elem) in fields.iter().zip(elems) {
            values.push(encode_value(field_type(field), elem)?);
        }
        return Ok(Value::List(values));
    }

    encode_scalar(typ, native)
}

/// Encode each native column, then format with [`format_row`].
pub fn format_result_row(
    types: &[Type],
    natives: &[NativeValue],
    config: &FormatConfig,
) -> Result<Vec<String>> {
    if types.len() != natives.len() {
        return Err(FormatError::RowLengthMismatch(format!(
            "len(types)={} != len(values)={}",
            types.len(),
            natives.len()
        )));
    }
    let mut wire = Vec::with_capacity(natives.len());
    for (typ, native) in types.iter().zip(natives) {
        wire.push(encode_value(typ, native)?);
    }
    format_row(types, &wire, config)
}

/// Decode a wire value to native form for round-trip encoder tests.
pub fn wire_to_native(typ: &Type, wire: &Value) -> Result<NativeValue> {
    if matches!(wire, Value::Null | Value::Missing) {
        return Ok(NativeValue::Null);
    }

    let code = typ.code;
    if code == TypeCode::Array as i32 {
        let elem_type = array_element_type(typ).ok_or_else(|| {
            FormatError::MalformedWire(crate::errors::MalformedWireError::new(
                "ARRAY missing array_element_type",
            ))
        })?;
        let elems = crate::types::list_values(wire).ok_or_else(|| {
            FormatError::MalformedWire(crate::errors::MalformedWireError::new(
                "ARRAY value is not a list",
            ))
        })?;
        return Ok(NativeValue::List(
            elems
                .iter()
                .map(|e| wire_to_native(elem_type, e))
                .collect::<Result<Vec<_>>>()?,
        ));
    }

    if code == TypeCode::Struct as i32 {
        let fields = struct_fields(typ);
        let elems = crate::types::list_values(wire).ok_or_else(|| {
            FormatError::MalformedWire(crate::errors::MalformedWireError::new(
                "STRUCT value is not a list",
            ))
        })?;
        if elems.len() != fields.len() {
            return Err(FormatError::MismatchedFields(
                crate::errors::MismatchedFieldsError::new(format!(
                    "got {} wire field values, want {}",
                    elems.len(),
                    fields.len()
                )),
            ));
        }
        return Ok(NativeValue::List(
            fields
                .iter()
                .zip(elems)
                .map(|(f, e)| wire_to_native(field_type(f), e))
                .collect::<Result<Vec<_>>>()?,
        ));
    }

    match wire {
        Value::Bool(v) => Ok(NativeValue::Bool(*v)),
        Value::Number(v) => Ok(NativeValue::F64(*v)),
        Value::String(s) => {
            if code == TypeCode::Float32 as i32 || code == TypeCode::Float64 as i32 {
                return Ok(match s.as_str() {
                    "NaN" => NativeValue::F64(f64::NAN),
                    "Infinity" => NativeValue::F64(f64::INFINITY),
                    "-Infinity" => NativeValue::F64(f64::NEG_INFINITY),
                    _ => NativeValue::Str(s.clone()),
                });
            }
            if code == TypeCode::Int64 as i32 || code == TypeCode::Enum as i32 {
                if let Ok(n) = s.parse::<i64>() {
                    return Ok(NativeValue::I64(n));
                }
            }
            if code == TypeCode::Bytes as i32 || code == TypeCode::Proto as i32 {
                return Ok(NativeValue::Bytes(bytes_fmt::decode_base64_wire(s)?));
            }
            Ok(NativeValue::Str(s.clone()))
        }
        _ => Err(FormatError::MalformedWire(crate::errors::MalformedWireError::new(
            format!("cannot decode wire value for type code {code}"),
        ))),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::format_config::simple_format_config;
    use crate::types::type_from_parts;
    use crate::types::{Field, StructType};

    #[test]
    fn encode_bool_round_trip() {
        let typ = type_from_parts(Some("BOOL"), None, None, None, None);
        let wire = encode_value(&typ, &NativeValue::Bool(true)).unwrap();
        assert_eq!(wire, Value::Bool(true));
    }

    #[test]
    fn format_result_row_small() {
        let int_typ = type_from_parts(Some("INT64"), None, None, None, None);
        let str_typ = type_from_parts(Some("STRING"), None, None, None, None);
        let config = simple_format_config("<null>").unwrap();
        let got = format_result_row(
            &[int_typ.clone(), str_typ.clone()],
            &[NativeValue::I64(1), NativeValue::Str("hi".into())],
            &config,
        )
        .unwrap();
        assert_eq!(got, vec!["1", "hi"]);
    }

    #[test]
    fn format_result_row_with_null_and_struct() {
        let struct_typ = type_from_parts(
            Some("STRUCT"),
            None,
            Some(StructType {
                fields: vec![
                    Field {
                        name: "n".into(),
                        field_type: type_from_parts(Some("INT64"), None, None, None, None),
                    },
                    Field {
                        name: "s".into(),
                        field_type: type_from_parts(Some("STRING"), None, None, None, None),
                    },
                ],
            }),
            None,
            None,
        );
        let config = simple_format_config("<null>").unwrap();
        let got = format_result_row(
            &[
                type_from_parts(Some("BOOL"), None, None, None, None),
                struct_typ.clone(),
            ],
            &[
                NativeValue::Null,
                NativeValue::List(vec![
                    NativeValue::I64(42),
                    NativeValue::Str("x".into()),
                ]),
            ],
            &config,
        )
        .unwrap();
        assert_eq!(got, vec!["<null>", "(42 AS n, x AS s)"]);
    }
}
