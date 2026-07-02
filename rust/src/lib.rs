//! Format Cloud Spanner types and column values.

pub mod bytes_fmt;
pub mod codes;
pub mod errors;
pub mod float_fmt;
pub mod format_config;
pub mod quote;
pub mod type_format;
pub mod types;

pub use bytes_fmt::{decode_base64_wire, readable_bytes_string, readable_string_from_base64_wire};
pub use codes::{
    parse_type_annotation, parse_type_code, type_annotation_name, type_code_name, TypeAnnotationCode,
    TypeCode, TYPE_ANNOTATION_NAMES, TYPE_CODE_NAMES,
};
pub use errors::{
    EmptyNullStringError, EmptyTypeFQNError, FormatError, MalformedWireError,
    MismatchedFieldsError, Result, SpanValueError, UnexpectedComplexValueKindError,
    UnknownTypeError,
};
pub use float_fmt::{
    float32_to_literal, float64_to_literal, format_go_g, format_spanner_cli_float, narrow_float32,
};
pub use format_config::{
    format_row, format_value, literal_format_config, simple_format_config,
    spanner_cli_format_config, FormatConfig, Preset,
};
pub use quote::{
    escape_rune, normalize_literal_quote, sql_cast_quoted, to_bytes_literal, to_string_literal,
    LiteralQuoteConfig, PreferredQuote, QuoteStrategy,
};
pub use type_format::{
    format_proto_enum, format_struct_fields, format_type, format_type_code,
    format_type_more_verbose, format_type_normal, format_type_simple, format_type_simplest,
    format_type_verbose, format_type_verbose_annotation_omit,
    format_type_verbose_annotation_primary, ArrayMode, FormatOption, ProtoEnumMode, StructMode,
    TypeAnnotationMode, UnknownMode, FORMAT_OPTION_MORE_VERBOSE, FORMAT_OPTION_NORMAL,
    FORMAT_OPTION_SIMPLE, FORMAT_OPTION_SIMPLEST, FORMAT_OPTION_VERBOSE,
};
pub use types::{
    array_element_type, bool_value, field_name, field_type, is_null_value, list_values,
    number_value, proto_type_fqn, string_value, struct_fields, type_code, type_from_parts,
    type_annotation, value_kind, Field, StructType, Type, Value, ValueKind,
};

pub const VERSION: &str = "0.1.0-alpha.0";
