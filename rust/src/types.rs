//! Cloud Spanner wire `Type` and `Value` representations.

use crate::codes::{parse_type_annotation, parse_type_code};

/// `google.spanner.v1.Type`.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Type {
    pub code: i32,
    pub array_element_type: Option<Box<Type>>,
    pub struct_type: Option<StructType>,
    pub proto_type_fqn: String,
    pub type_annotation: i32,
}

/// `google.spanner.v1.StructType`.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct StructType {
    pub fields: Vec<Field>,
}

/// `google.spanner.v1.StructType.Field`.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Field {
    pub name: String,
    pub field_type: Type,
}

/// `google.protobuf.Value` wire encoding.
#[derive(Clone, Debug, PartialEq)]
pub enum Value {
    Null,
    Bool(bool),
    Number(f64),
    String(String),
    List(Vec<Value>),
    Missing,
}

impl Default for Value {
    fn default() -> Self {
        Self::Missing
    }
}

impl Type {
    pub fn new(code: i32) -> Self {
        Self {
            code,
            ..Default::default()
        }
    }
}

pub fn type_code(typ: &Type) -> i32 {
    typ.code
}

pub fn type_annotation(typ: &Type) -> i32 {
    typ.type_annotation
}

pub fn proto_type_fqn(typ: &Type) -> &str {
    &typ.proto_type_fqn
}

pub fn array_element_type(typ: &Type) -> Option<&Type> {
    typ.array_element_type.as_deref()
}

pub fn struct_fields(typ: &Type) -> &[Field] {
    typ.struct_type
        .as_ref()
        .map(|st| st.fields.as_slice())
        .unwrap_or(&[])
}

pub fn field_name(field: &Field) -> &str {
    &field.name
}

pub fn field_type(field: &Field) -> &Type {
    &field.field_type
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ValueKind {
    Null,
    Bool,
    Number,
    String,
    List,
    Missing,
}

pub fn value_kind(value: &Value) -> ValueKind {
    match value {
        Value::Null => ValueKind::Null,
        Value::Bool(_) => ValueKind::Bool,
        Value::Number(_) => ValueKind::Number,
        Value::String(_) => ValueKind::String,
        Value::List(_) => ValueKind::List,
        Value::Missing => ValueKind::Missing,
    }
}

pub fn is_null_value(value: &Value) -> bool {
    matches!(value_kind(value), ValueKind::Null | ValueKind::Missing)
}

pub fn bool_value(value: &Value) -> Option<bool> {
    match value {
        Value::Bool(v) => Some(*v),
        _ => None,
    }
}

pub fn number_value(value: &Value) -> Option<f64> {
    match value {
        Value::Number(v) => Some(*v),
        _ => None,
    }
}

pub fn string_value(value: &Value) -> Option<&str> {
    match value {
        Value::String(v) => Some(v),
        _ => None,
    }
}

pub fn list_values(value: &Value) -> Option<&[Value]> {
    match value {
        Value::List(v) => Some(v),
        _ => None,
    }
}

/// Build a [`Type`] from protojson field values.
pub fn type_from_parts(
    code: Option<&str>,
    array_element_type: Option<Type>,
    struct_type: Option<StructType>,
    proto_type_fqn: Option<&str>,
    type_annotation: Option<&str>,
) -> Type {
    Type {
        code: parse_type_code(code),
        array_element_type: array_element_type.map(Box::new),
        struct_type,
        proto_type_fqn: proto_type_fqn.unwrap_or("").to_string(),
        type_annotation: parse_type_annotation(type_annotation),
    }
}
