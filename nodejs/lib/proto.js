/** Duck-typed access to protojson dicts and protobuf objects. */

import { parseTypeAnnotation, parseTypeCode } from './codes.js';

/**
 * @param {unknown} obj
 * @param {...string} names
 * @param {{ default?: unknown }} [opts]
 */
function get(obj, ...names) {
  const defaultValue = names.length > 0 && typeof names[names.length - 1] === 'object' &&
    names[names.length - 1] !== null && 'default' in names[names.length - 1]
    ? names.pop().default
    : undefined;

  if (obj == null) {
    return defaultValue;
  }
  if (typeof obj === 'object' && !Array.isArray(obj)) {
    for (const name of names) {
      if (Object.prototype.hasOwnProperty.call(obj, name)) {
        return /** @type {Record<string, unknown>} */ (obj)[name];
      }
    }
    if (typeof /** @type {{ get?: (k: string) => unknown }} */ (obj).get === 'function') {
      for (const name of names) {
        const val = /** @type {{ get: (k: string) => unknown }} */ (obj).get(name);
        if (val != null) {
          return val;
        }
      }
    }
    return defaultValue;
  }
  for (const name of names) {
    if (Object.prototype.hasOwnProperty.call(obj, name)) {
      return /** @type {Record<string, unknown>} */ (obj)[name];
    }
  }
  return defaultValue;
}

/** @param {unknown} typ */
export function typeCode(typ) {
  return parseTypeCode(get(typ, 'code', 'Code'));
}

/** @param {unknown} typ */
export function typeAnnotation(typ) {
  return parseTypeAnnotation(get(typ, 'type_annotation', 'typeAnnotation', 'TypeAnnotation'));
}

/** @param {unknown} typ */
export function protoTypeFqn(typ) {
  return get(typ, 'proto_type_fqn', 'protoTypeFqn', 'ProtoTypeFqn', { default: '' }) || '';
}

/** @param {unknown} typ */
export function arrayElementType(typ) {
  return get(typ, 'array_element_type', 'arrayElementType', 'ArrayElementType');
}

/** @param {unknown} typ */
export function structType(typ) {
  return get(typ, 'struct_type', 'structType', 'StructType');
}

/** @param {unknown} typ */
export function structFields(typ) {
  const st = structType(typ);
  if (st == null) {
    return [];
  }
  const fields = get(st, 'fields', 'Fields', { default: [] });
  return fields != null ? [.../** @type {unknown[]} */ (fields)] : [];
}

/** @param {unknown} field */
export function fieldName(field) {
  return get(field, 'name', 'Name', { default: '' }) || '';
}

/** @param {unknown} field */
export function fieldType(field) {
  return get(field, 'type', 'Type');
}

/**
 * Return wire kind: null, bool, number, string, list, or missing.
 * @param {unknown} value
 * @returns {'null' | 'bool' | 'number' | 'string' | 'list' | 'missing'}
 */
export function valueKind(value) {
  if (value == null) {
    return 'null';
  }
  if (typeof value === 'object' && !Array.isArray(value)) {
    const obj = /** @type {Record<string, unknown>} */ (value);
    if ('null_value' in obj || 'nullValue' in obj) {
      return 'null';
    }
    if ('bool_value' in obj || 'boolValue' in obj) {
      return 'bool';
    }
    if ('number_value' in obj || 'numberValue' in obj) {
      return 'number';
    }
    if ('string_value' in obj || 'stringValue' in obj) {
      return 'string';
    }
    if ('list_value' in obj || 'listValue' in obj) {
      return 'list';
    }
    return 'missing';
  }
  if (typeof value === 'boolean') {
    return 'bool';
  }
  if (typeof value === 'number') {
    return 'number';
  }
  if (typeof value === 'string') {
    return 'string';
  }
  if (Array.isArray(value)) {
    return 'list';
  }
  if (typeof value === 'object' && value !== null && typeof /** @type {{ WhichOneof?: (f: string) => string | undefined }} */ (value).WhichOneof === 'function') {
    const which = /** @type {{ WhichOneof: (f: string) => string | undefined }} */ (value).WhichOneof('kind');
    if (which == null) {
      return 'missing';
    }
    if (which === 'null_value' || which === 'nullValue') {
      return 'null';
    }
    if (which === 'bool_value' || which === 'boolValue') {
      return 'bool';
    }
    if (which === 'number_value' || which === 'numberValue') {
      return 'number';
    }
    if (which === 'string_value' || which === 'stringValue') {
      return 'string';
    }
    if (which === 'list_value' || which === 'listValue') {
      return 'list';
    }
  }
  for (const [attr, kind] of [
    ['bool_value', 'bool'],
    ['boolValue', 'bool'],
    ['number_value', 'number'],
    ['numberValue', 'number'],
    ['string_value', 'string'],
    ['stringValue', 'string'],
    ['list_value', 'list'],
    ['listValue', 'list'],
  ]) {
    if (Object.prototype.hasOwnProperty.call(value, attr)) {
      if (/** @type {Record<string, unknown>} */ (value)[attr] != null || kind === 'null') {
        return /** @type {'bool' | 'number' | 'string' | 'list' | 'null'} */ (kind);
      }
    }
  }
  return 'missing';
}

/** @param {unknown} value */
export function isNullValue(value) {
  const kind = valueKind(value);
  return kind === 'null' || kind === 'missing';
}

/** @param {unknown} value */
export function boolValue(value) {
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    const obj = /** @type {Record<string, unknown>} */ (value);
    return Boolean(obj.bool_value ?? obj.boolValue);
  }
  return Boolean(get(value, 'bool_value', 'boolValue'));
}

/** @param {unknown} value */
export function numberValue(value) {
  if (typeof value === 'number') {
    return value;
  }
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    const obj = /** @type {Record<string, unknown>} */ (value);
    return Number(obj.number_value ?? obj.numberValue);
  }
  return Number(get(value, 'number_value', 'numberValue'));
}

/** @param {unknown} value */
export function stringValue(value) {
  if (typeof value === 'string') {
    return value;
  }
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    const obj = /** @type {Record<string, unknown>} */ (value);
    return String(obj.string_value ?? obj.stringValue ?? '');
  }
  return String(get(value, 'string_value', 'stringValue', { default: '' }));
}

/** @param {unknown} value */
export function listValues(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    const obj = /** @type {Record<string, unknown>} */ (value);
    const lv = obj.list_value ?? obj.listValue;
    if (typeof lv === 'object' && lv !== null && !Array.isArray(lv)) {
      const listObj = /** @type {Record<string, unknown>} */ (lv);
      const vals = listObj.values ?? listObj.Values ?? [];
      return [.../** @type {unknown[]} */ (vals)];
    }
    if (lv != null && typeof lv === 'object' && 'values' in /** @type {object} */ (lv)) {
      return [.../** @type {{ values: unknown[] }} */ (lv).values];
    }
  }
  const lv = get(value, 'list_value', 'listValue');
  if (lv != null) {
    const vals = get(lv, 'values', 'Values', { default: [] });
    return vals != null ? [.../** @type {unknown[]} */ (vals)] : [];
  }
  return [];
}
