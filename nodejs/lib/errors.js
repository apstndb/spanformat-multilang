export class SpanValueError extends Error {}
export class MalformedWireError extends SpanValueError {}
export class UnknownTypeError extends SpanValueError {}
export class MismatchedFieldsError extends SpanValueError {}
export class EmptyTypeFQNError extends SpanValueError {}
export class UnexpectedComplexValueKindError extends SpanValueError {}
export class EmptyNullStringError extends SpanValueError {}
