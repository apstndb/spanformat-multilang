/** Format Cloud Spanner types and column values. */

export {
  SpanValueError,
  MalformedWireError,
  UnknownTypeError,
  MismatchedFieldsError,
  EmptyTypeFQNError,
  UnexpectedComplexValueKindError,
  EmptyNullStringError,
  TypeMismatchError,
} from './errors.js';

export {
  TypeCode,
  TypeAnnotationCode,
  parseTypeCode,
  parseTypeAnnotation,
  typeCodeName,
} from './codes.js';

export {
  QuoteStrategy,
  PreferredQuote,
  DEFAULT_LITERAL_QUOTE,
  normalizeLiteralQuote,
  quoteCharForPayload,
  escapeRune,
  toStringLiteral,
  toBytesLiteral,
  sqlCastQuoted,
} from './quote.js';

export {
  StructMode,
  ProtoEnumMode,
  ArrayMode,
  UnknownMode,
  TypeAnnotationMode,
  FORMAT_OPTION_SIMPLEST,
  FORMAT_OPTION_SIMPLE,
  FORMAT_OPTION_NORMAL,
  FORMAT_OPTION_VERBOSE,
  FORMAT_OPTION_MORE_VERBOSE,
  formatTypeCode,
  formatProtoEnum,
  formatStructFields,
  formatType,
  formatTypeSimplest,
  formatTypeSimple,
  formatTypeNormal,
  formatTypeVerbose,
  formatTypeMoreVerbose,
  formatTypeVerboseAnnotationOmit,
  formatTypeVerboseAnnotationPrimary,
} from './type-format.js';

export {
  Preset,
  createFormatConfig,
  simpleFormatConfig,
  literalFormatConfig,
  spannerCliFormatConfig,
  withNullString,
  formatValue,
  formatRow,
} from './format-config.js';

export {
  typeCode,
  typeAnnotation,
  protoTypeFqn,
  arrayElementType,
  structType,
  structFields,
  fieldName,
  fieldType,
  valueKind,
  isNullValue,
  boolValue,
  numberValue,
  stringValue,
  listValues,
} from './proto.js';

export {
  narrowFloat32,
  formatGoG,
  formatSpannerCliFloat,
  float64ToLiteral,
  float32ToLiteral,
} from './float-fmt.js';

export {
  decodeBase64Wire,
  readableBytesString,
  readableStringFromBase64Wire,
} from './bytes-fmt.js';

export { encodeValue, formatResultRow } from './encoder.js';

export { adaptClientType } from './client-type-adapter.js';

export const version = '0.1.0-alpha.0';
