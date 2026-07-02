//! Spanner TypeCode and TypeAnnotationCode constants.

use std::fmt;

/// Cloud Spanner `google.spanner.v1.TypeCode`.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
#[repr(i32)]
pub enum TypeCode {
    TypeCodeUnspecified = 0,
    Bool = 1,
    Int64 = 2,
    Float64 = 3,
    Float32 = 4,
    Timestamp = 5,
    Date = 6,
    String = 7,
    Bytes = 8,
    Array = 9,
    Struct = 10,
    Numeric = 11,
    Json = 12,
    Proto = 13,
    Enum = 14,
    Interval = 15,
    Uuid = 16,
}

impl TypeCode {
    pub fn from_i32(code: i32) -> Option<Self> {
        match code {
            0 => Some(Self::TypeCodeUnspecified),
            1 => Some(Self::Bool),
            2 => Some(Self::Int64),
            3 => Some(Self::Float64),
            4 => Some(Self::Float32),
            5 => Some(Self::Timestamp),
            6 => Some(Self::Date),
            7 => Some(Self::String),
            8 => Some(Self::Bytes),
            9 => Some(Self::Array),
            10 => Some(Self::Struct),
            11 => Some(Self::Numeric),
            12 => Some(Self::Json),
            13 => Some(Self::Proto),
            14 => Some(Self::Enum),
            15 => Some(Self::Interval),
            16 => Some(Self::Uuid),
            _ => None,
        }
    }

    pub fn name(self) -> Option<&'static str> {
        type_code_name(self as i32)
    }
}

pub const TYPE_CODE_NAMES: &[(i32, &str)] = &[
    (0, "TYPE_CODE_UNSPECIFIED"),
    (1, "BOOL"),
    (2, "INT64"),
    (3, "FLOAT64"),
    (4, "FLOAT32"),
    (5, "TIMESTAMP"),
    (6, "DATE"),
    (7, "STRING"),
    (8, "BYTES"),
    (9, "ARRAY"),
    (10, "STRUCT"),
    (11, "NUMERIC"),
    (12, "JSON"),
    (13, "PROTO"),
    (14, "ENUM"),
    (15, "INTERVAL"),
    (16, "UUID"),
];

/// Cloud Spanner `google.spanner.v1.TypeAnnotationCode`.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
#[repr(i32)]
pub enum TypeAnnotationCode {
    TypeAnnotationCodeUnspecified = 0,
    PgNumeric = 2,
    PgJsonb = 3,
    PgOid = 4,
}

pub const TYPE_ANNOTATION_NAMES: &[(i32, &str)] = &[
    (0, "TYPE_ANNOTATION_CODE_UNSPECIFIED"),
    (2, "PG_NUMERIC"),
    (3, "PG_JSONB"),
    (4, "PG_OID"),
];

pub fn type_code_name(code: i32) -> Option<&'static str> {
    TYPE_CODE_NAMES
        .iter()
        .find(|(v, _)| *v == code)
        .map(|(_, name)| *name)
}

pub fn type_annotation_name(code: i32) -> Option<&'static str> {
    TYPE_ANNOTATION_NAMES
        .iter()
        .find(|(v, _)| *v == code)
        .map(|(_, name)| *name)
}

/// Accept enum name or numeric code from protojson.
pub fn parse_type_code(value: Option<&str>) -> i32 {
    match value {
        None => 0,
        Some(s) => parse_enum_string(s, TYPE_CODE_NAMES),
    }
}

/// Accept enum name or numeric annotation from protojson.
pub fn parse_type_annotation(value: Option<&str>) -> i32 {
    match value {
        None => 0,
        Some(s) => parse_enum_string(s, TYPE_ANNOTATION_NAMES),
    }
}

fn parse_enum_string(s: &str, table: &[(i32, &str)]) -> i32 {
    if let Ok(n) = s.parse::<i32>() {
        return n;
    }
    table
        .iter()
        .find(|(_, name)| *name == s)
        .map(|(v, _)| *v)
        .unwrap_or(0)
}

impl fmt::Display for TypeCode {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self.name() {
            Some(name) => write!(f, "{name}"),
            None => write!(f, "{}", *self as i32),
        }
    }
}
