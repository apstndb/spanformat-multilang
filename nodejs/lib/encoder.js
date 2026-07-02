/** Wire encoders: native values → google.protobuf.Value wire shapes. */

import { Buffer } from 'node:buffer';
import { TypeCode, typeCodeName } from './codes.js';
import {
  MismatchedFieldsError,
  UnknownTypeError,
} from './errors.js';
import { formatRow } from './format-config.js';
import {
  arrayElementType,
  fieldName,
  fieldType,
  structFields,
  typeCode,
} from './proto.js';

/**
 * @param {unknown} value
 */
function isNativeNull(value) {
  return value === null || value === undefined;
}

/**
 * @param {unknown} v
 */
function encodeInt64(v) {
  if (typeof v === 'bigint') {
    return String(v);
  }
  if (typeof v === 'number') {
    if (!Number.isInteger(v)) {
      throw new TypeError(`INT64 native value must be an integer: ${v}`);
    }
    return String(v);
  }
  if (typeof v === 'string') {
    if (!/^-?\d+$/.test(v)) {
      throw new TypeError(`INT64 native value must be a decimal integer string: ${JSON.stringify(v)}`);
    }
    return v;
  }
  throw new TypeError(`INT64 native value must be number, bigint, or decimal string: ${typeof v}`);
}

/**
 * @param {unknown} v
 */
function encodeFloat(v) {
  if (typeof v === 'string') {
    if (v === 'NaN' || v === 'Infinity' || v === '-Infinity') {
      return v;
    }
    v = Number(v);
  }
  if (typeof v !== 'number') {
    throw new TypeError(`FLOAT native value must be a number: ${typeof v}`);
  }
  if (Number.isNaN(v)) {
    return 'NaN';
  }
  if (v === Infinity) {
    return 'Infinity';
  }
  if (v === -Infinity) {
    return '-Infinity';
  }
  return v;
}

/**
 * @param {unknown} v
 */
function encodeBytes(v) {
  if (typeof v === 'string') {
    return v;
  }
  if (Buffer.isBuffer(v)) {
    return v.toString('base64');
  }
  if (v instanceof Uint8Array) {
    return Buffer.from(v).toString('base64');
  }
  throw new TypeError(`BYTES/PROTO native value must be a string or Buffer: ${typeof v}`);
}

/**
 * @param {unknown} v
 */
function encodeJSON(v) {
  if (typeof v === 'string') {
    JSON.parse(v);
    return v;
  }
  return JSON.stringify(v);
}

/**
 * @param {unknown} typ
 * @param {unknown} nativeValue
 * @param {{ index?: number, fieldName?: string }} [ctx]
 */
export function encodeValue(typ, nativeValue, ctx = {}) {
  if (isNativeNull(nativeValue)) {
    return null;
  }

  const code = typeCode(typ);
  const label = typeCodeName(code) ?? String(code);

  try {
    switch (code) {
      case TypeCode.BOOL:
        if (typeof nativeValue !== 'boolean') {
          throw new TypeError(`BOOL native value must be boolean: ${typeof nativeValue}`);
        }
        return nativeValue;

      case TypeCode.INT64:
      case TypeCode.ENUM:
        return encodeInt64(nativeValue);

      case TypeCode.FLOAT32:
      case TypeCode.FLOAT64:
        return encodeFloat(nativeValue);

      case TypeCode.STRING:
      case TypeCode.TIMESTAMP:
      case TypeCode.DATE:
      case TypeCode.NUMERIC:
      case TypeCode.INTERVAL:
      case TypeCode.UUID:
        if (typeof nativeValue !== 'string') {
          throw new TypeError(`${label} native value must be a string: ${typeof nativeValue}`);
        }
        return nativeValue;

      case TypeCode.JSON:
        return encodeJSON(nativeValue);

      case TypeCode.BYTES:
      case TypeCode.PROTO:
        return encodeBytes(nativeValue);

      case TypeCode.ARRAY: {
        if (!Array.isArray(nativeValue)) {
          throw new TypeError('ARRAY native value must be an array');
        }
        const elemType = arrayElementType(typ);
        if (elemType == null) {
          throw new UnknownTypeError('ARRAY type missing array_element_type');
        }
        return nativeValue.map((elem, i) => encodeValue(elemType, elem, { index: i }));
      }

      case TypeCode.STRUCT: {
        const fields = structFields(typ);
        let fieldValues;
        if (Array.isArray(nativeValue)) {
          fieldValues = nativeValue;
        } else if (typeof nativeValue === 'object' && nativeValue !== null) {
          fieldValues = fields.map((field) => {
            const name = fieldName(field);
            if (name && Object.prototype.hasOwnProperty.call(nativeValue, name)) {
              return /** @type {Record<string, unknown>} */ (nativeValue)[name];
            }
            return null;
          });
        } else {
          throw new TypeError('STRUCT native value must be an array or object');
        }
        if (fieldValues.length !== fields.length) {
          throw new MismatchedFieldsError(`got ${fieldValues.length} values, want ${fields.length}`);
        }
        return fields.map((field, i) =>
          encodeValue(fieldType(field), fieldValues[i], { index: i, fieldName: fieldName(field) }),
        );
      }

      default:
        throw new UnknownTypeError(`cannot encode type code ${label}`);
    }
  } catch (err) {
    if (err instanceof MismatchedFieldsError) {
      throw err;
    }
    if (err instanceof TypeError) {
      if (ctx.fieldName) {
        throw new TypeError(`struct field ${ctx.index} (${JSON.stringify(ctx.fieldName)}): ${err.message}`);
      }
      if (ctx.index != null) {
        throw new TypeError(`array element ${ctx.index}: ${err.message}`);
      }
    }
    throw err;
  }
}

/**
 * @param {unknown[]} types
 * @param {unknown[]} values
 * @param {import('./format-config.js').FormatConfig} config
 */
export function formatResultRow(types, values, config) {
  if (types.length !== values.length) {
    throw new Error(`len(types)=${types.length} != len(values)=${values.length}`);
  }
  const wireValues = types.map((typ, i) => encodeValue(typ, values[i]));
  return formatRow(types, wireValues, config);
}
