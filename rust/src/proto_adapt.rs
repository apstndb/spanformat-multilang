//! Duck-typed adapters for protobuf-shaped types without prost/protobuf deps.

use crate::format_config::{format_value, FormatConfig};
use crate::types::{StructType, Type, Value};
use crate::Result;

/// Structural access to `google.spanner.v1.Type`-like values.
pub trait TypeLike {
    fn spanner_code(&self) -> i32;
    fn spanner_type_annotation(&self) -> i32 {
        0
    }
    fn spanner_proto_type_fqn(&self) -> &str {
        ""
    }
    fn spanner_array_element_type(&self) -> Option<Type> {
        None
    }
    fn spanner_struct_type(&self) -> Option<StructType> {
        None
    }
}

/// Convert protobuf-shaped values to native [`Value`].
pub trait ValueLike {
    fn to_wire_value(&self) -> Value;
}

impl TypeLike for Type {
    fn spanner_code(&self) -> i32 {
        self.code
    }

    fn spanner_type_annotation(&self) -> i32 {
        self.type_annotation
    }

    fn spanner_proto_type_fqn(&self) -> &str {
        &self.proto_type_fqn
    }

    fn spanner_array_element_type(&self) -> Option<Type> {
        self.array_element_type.as_deref().cloned()
    }

    fn spanner_struct_type(&self) -> Option<StructType> {
        self.struct_type.clone()
    }
}

impl ValueLike for Value {
    fn to_wire_value(&self) -> Value {
        self.clone()
    }
}

impl ValueLike for &Value {
    fn to_wire_value(&self) -> Value {
        (*self).clone()
    }
}

/// Convert any [`TypeLike`] value to native [`Type`].
pub fn adapt_type<T: TypeLike>(typ: &T) -> Type {
    Type {
        code: typ.spanner_code(),
        array_element_type: typ.spanner_array_element_type().map(Box::new),
        struct_type: typ.spanner_struct_type(),
        proto_type_fqn: typ.spanner_proto_type_fqn().to_string(),
        type_annotation: typ.spanner_type_annotation(),
    }
}

/// Format a protobuf-shaped `(Type, Value)` pair.
pub fn format_value_like<T: TypeLike, V: ValueLike>(
    typ: &T,
    value: &V,
    config: &FormatConfig,
    toplevel: bool,
) -> Result<String> {
    format_value(&adapt_type(typ), &value.to_wire_value(), config, toplevel)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::format_config::simple_format_config;
    use crate::types::type_from_parts;

    #[test]
    fn adapt_type_round_trip() {
        let typ = type_from_parts(Some("INT64"), None, None, None, None);
        let adapted = adapt_type(&typ);
        assert_eq!(adapted, typ);
    }

    #[test]
    fn format_value_like_int64() {
        let typ = type_from_parts(Some("INT64"), None, None, None, None);
        let value = Value::String("7".into());
        let config = simple_format_config("<null>").unwrap();
        let got = format_value_like(&typ, &value, &config, true).unwrap();
        assert_eq!(got, "7");
    }
}
