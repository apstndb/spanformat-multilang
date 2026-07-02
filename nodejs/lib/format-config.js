/** FormatConfig presets and value formatting. */

import { TypeCode } from './codes.js';
import {
  EmptyNullStringError,
  EmptyTypeFQNError,
  MalformedWireError,
  MismatchedFieldsError,
  UnexpectedComplexValueKindError,
  UnknownTypeError,
} from './errors.js';
import {
  arrayElementType,
  boolValue,
  fieldName,
  fieldType,
  isNullValue,
  listValues,
  numberValue,
  protoTypeFqn,
  stringValue,
  structFields,
  typeCode,
  valueKind,
} from './proto.js';
import {
  DEFAULT_LITERAL_QUOTE,
  normalizeLiteralQuote,
  sqlCastQuoted,
  toBytesLiteral,
  toStringLiteral,
} from './quote.js';
import { decodeBase64Wire, readableStringFromBase64Wire } from './bytes-fmt.js';
import {
  float32ToLiteral,
  float64ToLiteral,
  formatGoG,
  formatSpannerCliFloat,
  narrowFloat32,
} from './float-fmt.js';
import { formatTypeCode, formatTypeVerbose } from './type-format.js';

export const Preset = Object.freeze({
  SIMPLE: 0,
  LITERAL: 1,
  SPANNER_CLI: 2,
});

/**
 * @typedef {Object} FormatConfig
 * @property {number} preset
 * @property {string} nullString
 * @property {import('./quote.js').LiteralQuoteConfig} quote
 */

/**
 * @param {Partial<FormatConfig>} [opts]
 * @returns {FormatConfig}
 */
export function createFormatConfig(opts = {}) {
  const nullString = opts.nullString ?? '<null>';
  if (!nullString) {
    throw new EmptyNullStringError('null_string must not be empty');
  }
  return {
    preset: opts.preset ?? Preset.SIMPLE,
    nullString,
    quote: normalizeLiteralQuote(opts.quote ?? DEFAULT_LITERAL_QUOTE),
  };
}

/** @param {string} [nullString] */
export function simpleFormatConfig(nullString = '<null>') {
  return createFormatConfig({ preset: Preset.SIMPLE, nullString });
}

/**
 * @param {import('./quote.js').LiteralQuoteConfig | null | undefined} [quote]
 * @param {string} [nullString]
 */
export function literalFormatConfig(quote, nullString = 'NULL') {
  return createFormatConfig({
    preset: Preset.LITERAL,
    nullString,
    quote: normalizeLiteralQuote(quote ?? DEFAULT_LITERAL_QUOTE),
  });
}

/** @param {string} [nullString] */
export function spannerCliFormatConfig(nullString = 'NULL') {
  return createFormatConfig({ preset: Preset.SPANNER_CLI, nullString });
}

/**
 * @param {FormatConfig} config
 * @param {string} nullString
 */
export function withNullString(config, nullString) {
  return createFormatConfig({ ...config, nullString });
}

/**
 * @param {number} code
 */
function isComplexType(code) {
  return code === TypeCode.ARRAY || code === TypeCode.STRUCT;
}

/**
 * @param {number} code
 */
function isScalarType(code) {
  return code === TypeCode.BOOL ||
    code === TypeCode.INT64 ||
    code === TypeCode.ENUM ||
    code === TypeCode.FLOAT32 ||
    code === TypeCode.FLOAT64 ||
    code === TypeCode.STRING ||
    code === TypeCode.BYTES ||
    code === TypeCode.PROTO ||
    code === TypeCode.TIMESTAMP ||
    code === TypeCode.DATE ||
    code === TypeCode.NUMERIC ||
    code === TypeCode.JSON ||
    code === TypeCode.INTERVAL ||
    code === TypeCode.UUID;
}

/**
 * @param {unknown} value
 * @param {number} code
 */
function requireStringWire(value, code) {
  if (valueKind(value) !== 'string') {
    throw new MalformedWireError(`${formatTypeCode(code)} value kind ${valueKind(value)}`);
  }
}

/**
 * @param {unknown} value
 * @param {number} code
 */
function requireBoolWire(value, code) {
  if (valueKind(value) !== 'bool') {
    throw new MalformedWireError(`${formatTypeCode(code)} value kind ${valueKind(value)}`);
  }
}

/**
 * @param {unknown} value
 * @param {number} code
 */
function validateFloatWire(value, code) {
  const kind = valueKind(value);
  if (kind === 'number') {
    return;
  }
  if (kind === 'string') {
    const s = stringValue(value);
    if (s === 'NaN' || s === 'Infinity' || s === '-Infinity') {
      return;
    }
    throw new MalformedWireError(`${formatTypeCode(code)} unexpected float string ${JSON.stringify(s)}`);
  }
  throw new MalformedWireError(`${formatTypeCode(code)} value kind ${kind}`);
}

/** @param {unknown} value */
function gcvFloat64(value) {
  const kind = valueKind(value);
  if (kind === 'number') {
    return numberValue(value);
  }
  if (kind === 'string') {
    const s = stringValue(value);
    if (s === 'NaN') {
      return Number.NaN;
    }
    if (s === 'Infinity') {
      return Number.POSITIVE_INFINITY;
    }
    if (s === '-Infinity') {
      return Number.NEGATIVE_INFINITY;
    }
    throw new MalformedWireError(`FLOAT64 unexpected float string ${JSON.stringify(s)}`);
  }
  throw new MalformedWireError(`FLOAT64 value kind ${kind}`);
}

/** @param {unknown} value */
function gcvFloat32(value) {
  return narrowFloat32(gcvFloat64(value));
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 */
function validateScalarWire(typ, value) {
  if (typ == null) {
    throw new MalformedWireError(`nil type with value kind ${valueKind(value)}`);
  }
  if (isNullValue(value)) {
    throw new MalformedWireError(`${formatTypeCode(typeCode(typ))} unexpected null value`);
  }
  const code = typeCode(typ);
  if (code === TypeCode.BOOL) {
    requireBoolWire(value, code);
  } else if (
    code === TypeCode.INT64 ||
    code === TypeCode.ENUM ||
    code === TypeCode.STRING ||
    code === TypeCode.BYTES ||
    code === TypeCode.PROTO ||
    code === TypeCode.TIMESTAMP ||
    code === TypeCode.DATE ||
    code === TypeCode.NUMERIC ||
    code === TypeCode.INTERVAL ||
    code === TypeCode.UUID ||
    code === TypeCode.JSON
  ) {
    requireStringWire(value, code);
  } else if (code === TypeCode.FLOAT32 || code === TypeCode.FLOAT64) {
    validateFloatWire(value, code);
  } else if (code === TypeCode.TYPE_CODE_UNSPECIFIED) {
    throw new UnknownTypeError(String(typ));
  } else if (!isScalarType(code)) {
    throw new UnknownTypeError(String(typ));
  }
}

/**
 * @param {string} s
 */
function trimSpannerCliNumericFraction(s) {
  if (!s.includes('.')) {
    return s;
  }
  return s.replace(/0+$/, '').replace(/\.$/, '');
}

/** @param {unknown} value */
function numericWireString(value) {
  return stringValue(value);
}

/**
 * @param {string} typeName
 * @param {string} payload
 * @param {import('./quote.js').LiteralQuoteConfig} quote
 */
function stringBasedLiteral(typeName, payload, quote) {
  return `${typeName} ${toStringLiteral(payload, quote)}`;
}

/**
 * @param {string} s
 */
function validateInt64Wire(s) {
  if (!/^-?\d+$/.test(s)) {
    throw new MalformedWireError(`invalid INT64 wire ${JSON.stringify(s)}`);
  }
  try {
    const v = BigInt(s);
    const min = -(2n ** 63n);
    const max = 2n ** 63n - 1n;
    if (v < min || v > max) {
      throw new MalformedWireError(`INT64 out of range ${JSON.stringify(s)}`);
    }
  } catch (err) {
    if (err instanceof MalformedWireError) {
      throw err;
    }
    throw new MalformedWireError(`invalid INT64 wire ${JSON.stringify(s)}`);
  }
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 */
function formatScalarSimple(typ, value) {
  validateScalarWire(typ, value);
  const code = typeCode(typ);
  if (code === TypeCode.BOOL) {
    return boolValue(value) ? 'true' : 'false';
  }
  if (
    code === TypeCode.INT64 ||
    code === TypeCode.ENUM ||
    code === TypeCode.STRING ||
    code === TypeCode.TIMESTAMP ||
    code === TypeCode.DATE ||
    code === TypeCode.JSON ||
    code === TypeCode.INTERVAL ||
    code === TypeCode.UUID
  ) {
    return stringValue(value);
  }
  if (code === TypeCode.FLOAT32) {
    return formatGoG(gcvFloat32(value), 32);
  }
  if (code === TypeCode.FLOAT64) {
    return formatGoG(gcvFloat64(value), 64);
  }
  if (code === TypeCode.BYTES || code === TypeCode.PROTO) {
    return readableStringFromBase64Wire(stringValue(value));
  }
  if (code === TypeCode.NUMERIC) {
    return numericWireString(value);
  }
  throw new UnknownTypeError(String(typ));
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 * @param {import('./quote.js').LiteralQuoteConfig} quote
 */
function formatScalarLiteral(typ, value, quote) {
  validateScalarWire(typ, value);
  const code = typeCode(typ);
  if (code === TypeCode.BOOL) {
    return boolValue(value) ? 'true' : 'false';
  }
  if (code === TypeCode.INT64) {
    const s = stringValue(value);
    validateInt64Wire(s);
    return s;
  }
  if (code === TypeCode.FLOAT32) {
    return float32ToLiteral(gcvFloat32(value), quote);
  }
  if (code === TypeCode.FLOAT64) {
    return float64ToLiteral(gcvFloat64(value), quote);
  }
  if (code === TypeCode.STRING) {
    return toStringLiteral(stringValue(value), quote);
  }
  if (code === TypeCode.BYTES || code === TypeCode.PROTO) {
    const data = decodeBase64Wire(stringValue(value));
    return toBytesLiteral(data, quote);
  }
  if (code === TypeCode.TIMESTAMP) {
    return stringBasedLiteral('TIMESTAMP', stringValue(value), quote);
  }
  if (code === TypeCode.DATE) {
    return stringBasedLiteral('DATE', stringValue(value), quote);
  }
  if (code === TypeCode.NUMERIC) {
    return stringBasedLiteral('NUMERIC', numericWireString(value), quote);
  }
  if (code === TypeCode.JSON) {
    return stringBasedLiteral('JSON', stringValue(value), quote);
  }
  if (code === TypeCode.INTERVAL) {
    return sqlCastQuoted(stringValue(value), 'INTERVAL', quote);
  }
  if (code === TypeCode.UUID) {
    return sqlCastQuoted(stringValue(value), 'UUID', quote);
  }
  throw new UnknownTypeError(String(typ));
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 */
function formatScalarSpannerCli(typ, value) {
  validateScalarWire(typ, value);
  const code = typeCode(typ);
  if (code === TypeCode.BOOL) {
    return boolValue(value) ? 'true' : 'false';
  }
  if (
    code === TypeCode.INT64 ||
    code === TypeCode.ENUM ||
    code === TypeCode.STRING ||
    code === TypeCode.BYTES ||
    code === TypeCode.PROTO ||
    code === TypeCode.TIMESTAMP ||
    code === TypeCode.DATE ||
    code === TypeCode.INTERVAL ||
    code === TypeCode.UUID ||
    code === TypeCode.JSON
  ) {
    return stringValue(value);
  }
  if (code === TypeCode.FLOAT32) {
    return formatSpannerCliFloat(gcvFloat32(value), 32);
  }
  if (code === TypeCode.FLOAT64) {
    return formatSpannerCliFloat(gcvFloat64(value), 64);
  }
  if (code === TypeCode.NUMERIC) {
    return trimSpannerCliNumericFraction(numericWireString(value));
  }
  throw new UnknownTypeError(String(typ));
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 * @param {import('./quote.js').LiteralQuoteConfig} quote
 * @param {string} nullString
 */
function formatProtoLiteral(typ, value, quote, nullString) {
  if (typeCode(typ) !== TypeCode.PROTO) {
    throw new UnknownTypeError(String(typ));
  }
  if (isNullValue(value)) {
    return nullString;
  }
  requireStringWire(value, TypeCode.PROTO);
  const data = decodeBase64Wire(stringValue(value));
  const fqn = protoTypeFqn(typ);
  if (!fqn) {
    throw new EmptyTypeFQNError('empty type FQN for PROTO');
  }
  return `CAST(${toBytesLiteral(data, quote)} AS \`${fqn}\`)`;
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 * @param {string} nullString
 */
function formatEnumLiteral(typ, value, nullString) {
  if (typeCode(typ) !== TypeCode.ENUM) {
    throw new UnknownTypeError(String(typ));
  }
  if (isNullValue(value)) {
    return nullString;
  }
  requireStringWire(value, TypeCode.ENUM);
  const s = stringValue(value);
  if (!/^-?\d+$/.test(s)) {
    throw new MalformedWireError(`failed to parse enum wire payload ${JSON.stringify(s)}`);
  }
  const fqn = protoTypeFqn(typ);
  if (!fqn) {
    throw new EmptyTypeFQNError('empty type FQN for ENUM');
  }
  return `CAST(${s} AS \`${fqn}\`)`;
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 * @param {string} nullString
 */
function formatEnumSimple(typ, value, nullString) {
  if (isNullValue(value)) {
    return nullString;
  }
  return formatScalarSimple(typ, value);
}

/**
 * @param {unknown} typ
 * @param {unknown} value
 * @param {number} expectedCode
 */
function getListValue(typ, value, expectedCode) {
  if (valueKind(value) !== 'list') {
    throw new UnexpectedComplexValueKindError(
      `unexpected complex value kind for ${formatTypeCode(expectedCode)}: ${valueKind(value)}`,
    );
  }
  return listValues(value);
}

/**
 * Format one column value using the given config.
 * @param {unknown} typ
 * @param {unknown} value
 * @param {FormatConfig} config
 * @param {{ toplevel?: boolean }} [opts]
 */
export function formatValue(typ, value, config, opts = {}) {
  const toplevel = opts.toplevel ?? true;
  if (isNullValue(value)) {
    return config.nullString;
  }

  const code = typeCode(typ);

  if (code === TypeCode.ARRAY) {
    const elems = getListValue(typ, value, code);
    const elemType = arrayElementType(typ);
    const parts = elems.map((elem) => formatValue(elemType, elem, config, { toplevel: false }));
    const joined = parts.join(', ');
    if (config.preset === Preset.LITERAL && toplevel && isComplexType(typeCode(elemType))) {
      return `${formatTypeVerbose(typ)}[${joined}]`;
    }
    return `[${joined}]`;
  }

  if (code === TypeCode.STRUCT) {
    const fieldVals = getListValue(typ, value, code);
    const fields = structFields(typ);
    if (fieldVals.length !== fields.length) {
      throw new MismatchedFieldsError(`got ${fieldVals.length} values, want ${fields.length}`);
    }
    if (config.preset === Preset.SIMPLE) {
      const fieldStrs = [];
      for (let i = 0; i < fields.length; i++) {
        const rendered = formatValue(fieldType(fields[i]), fieldVals[i], config, { toplevel: false });
        const name = fieldName(fields[i]);
        if (name) {
          fieldStrs.push(`${rendered} AS ${name}`);
        } else {
          fieldStrs.push(rendered);
        }
      }
      return `(${fieldStrs.join(', ')})`;
    }
    const fieldStrs = fields.map((field, i) =>
      formatValue(fieldType(field), fieldVals[i], config, { toplevel: false }),
    );
    const inner = fieldStrs.join(', ');
    if (config.preset === Preset.LITERAL) {
      const prefix = toplevel ? formatTypeVerbose(typ) : '';
      return `${prefix}(${inner})`;
    }
    if (config.preset === Preset.SPANNER_CLI) {
      return `[${inner}]`;
    }
    return `(${inner})`;
  }

  if (code === TypeCode.PROTO) {
    if (config.preset === Preset.LITERAL) {
      return formatProtoLiteral(typ, value, config.quote, config.nullString);
    }
    requireStringWire(value, code);
    if (config.preset === Preset.SPANNER_CLI) {
      return stringValue(value);
    }
    return readableStringFromBase64Wire(stringValue(value));
  }

  if (code === TypeCode.ENUM) {
    if (config.preset === Preset.LITERAL) {
      return formatEnumLiteral(typ, value, config.nullString);
    }
    return formatEnumSimple(typ, value, config.nullString);
  }

  if (code === TypeCode.TYPE_CODE_UNSPECIFIED || !isScalarType(code)) {
    throw new UnknownTypeError(String(typ));
  }

  if (config.preset === Preset.SIMPLE) {
    return formatScalarSimple(typ, value);
  }
  if (config.preset === Preset.LITERAL) {
    return formatScalarLiteral(typ, value, config.quote);
  }
  return formatScalarSpannerCli(typ, value);
}

/**
 * Format a row of column values.
 * @param {unknown[]} types
 * @param {unknown[]} values
 * @param {FormatConfig} config
 */
export function formatRow(types, values, config) {
  if (types.length !== values.length) {
    throw new Error(`len(types)=${types.length} != len(values)=${values.length}`);
  }
  return types.map((t, i) => formatValue(t, values[i], config, { toplevel: true }));
}
