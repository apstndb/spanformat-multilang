//! Error types for spanvalue formatting.

use std::fmt;

/// Base class for spanvalue formatting errors.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct SpanValueError {
    message: String,
}

impl SpanValueError {
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }

    pub fn message(&self) -> &str {
        &self.message
    }
}

impl fmt::Display for SpanValueError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.message)
    }
}

impl std::error::Error for SpanValueError {}

/// Wire payload does not match the expected encoding for the type.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct MalformedWireError(pub String);

impl MalformedWireError {
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

impl fmt::Display for MalformedWireError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl std::error::Error for MalformedWireError {}

/// Type code is not supported by the formatter.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct UnknownTypeError(pub String);

impl UnknownTypeError {
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

impl fmt::Display for UnknownTypeError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl std::error::Error for UnknownTypeError {}

/// STRUCT wire value count does not match field descriptors.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct MismatchedFieldsError(pub String);

impl MismatchedFieldsError {
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

impl fmt::Display for MismatchedFieldsError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl std::error::Error for MismatchedFieldsError {}

/// PROTO or ENUM type is missing proto_type_fqn.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct EmptyTypeFQNError(pub String);

impl EmptyTypeFQNError {
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

impl fmt::Display for EmptyTypeFQNError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl std::error::Error for EmptyTypeFQNError {}

/// ARRAY or STRUCT value is not encoded as a list.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct UnexpectedComplexValueKindError(pub String);

impl UnexpectedComplexValueKindError {
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

impl fmt::Display for UnexpectedComplexValueKindError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl std::error::Error for UnexpectedComplexValueKindError {}

/// FormatConfig null_string must not be empty.
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct EmptyNullStringError(pub String);

impl EmptyNullStringError {
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

impl fmt::Display for EmptyNullStringError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

impl std::error::Error for EmptyNullStringError {}

/// Union of all formatting errors.
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum FormatError {
    SpanValue(SpanValueError),
    MalformedWire(MalformedWireError),
    UnknownType(UnknownTypeError),
    MismatchedFields(MismatchedFieldsError),
    EmptyTypeFQN(EmptyTypeFQNError),
    UnexpectedComplexValueKind(UnexpectedComplexValueKindError),
    EmptyNullString(EmptyNullStringError),
    RowLengthMismatch(String),
}

impl fmt::Display for FormatError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::SpanValue(e) => e.fmt(f),
            Self::MalformedWire(e) => e.fmt(f),
            Self::UnknownType(e) => e.fmt(f),
            Self::MismatchedFields(e) => e.fmt(f),
            Self::EmptyTypeFQN(e) => e.fmt(f),
            Self::UnexpectedComplexValueKind(e) => e.fmt(f),
            Self::EmptyNullString(e) => e.fmt(f),
            Self::RowLengthMismatch(s) => write!(f, "{s}"),
        }
    }
}

impl std::error::Error for FormatError {}

pub type Result<T> = std::result::Result<T, FormatError>;
