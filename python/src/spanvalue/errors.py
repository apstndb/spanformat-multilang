"""Error types for spanvalue formatting."""


class SpanValueError(Exception):
    """Base class for spanvalue formatting errors."""


class MalformedWireError(SpanValueError):
    """Wire payload does not match the expected encoding for the type."""


class UnknownTypeError(SpanValueError):
    """Type code is not supported by the formatter."""


class MismatchedFieldsError(SpanValueError):
    """STRUCT wire value count does not match field descriptors."""


class EmptyTypeFQNError(SpanValueError):
    """PROTO or ENUM type is missing proto_type_fqn."""


class UnexpectedComplexValueKindError(SpanValueError):
    """ARRAY or STRUCT value is not encoded as a list."""


class EmptyNullStringError(SpanValueError):
    """FormatConfig null_string must not be empty."""
