import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

import {
  adaptClientType,
  encodeValue,
  formatResultRow,
  formatValue,
  literalFormatConfig,
  simpleFormatConfig,
  boolValue,
  isNullValue,
  listValues,
  numberValue,
  stringValue,
  typeCode,
  valueKind,
  arrayElementType,
  fieldType,
  structFields,
  TypeCode,
} from '../lib/index.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CONFORMANCE_PATH = path.resolve(__dirname, '../../testdata/conformance.json');

/** @returns {import('../../testdata/conformance.json')} */
function loadConformance() {
  return JSON.parse(readFileSync(CONFORMANCE_PATH, 'utf8'));
}

/**
 * @param {unknown} a
 * @param {unknown} b
 */
function wireEqual(a, b) {
  assert.deepEqual(normalizeWire(a), normalizeWire(b));
}

/**
 * @param {unknown} value
 */
function normalizeWire(value) {
  if (value === null || isNullValue(value)) {
    return null;
  }
  const kind = valueKind(value);
  if (kind === 'bool') {
    return boolValue(value);
  }
  if (kind === 'number') {
    return numberValue(value);
  }
  if (kind === 'string') {
    return stringValue(value);
  }
  if (kind === 'list') {
    return listValues(value).map((elem) => normalizeWire(elem));
  }
  return value;
}

/**
 * Decode conformance wire value to a native value for encode round-trips.
 * @param {unknown} typ
 * @param {unknown} value
 */
function wireToNative(typ, value) {
  if (value === null || isNullValue(value)) {
    return null;
  }
  const code = typeCode(typ);
  switch (code) {
    case TypeCode.BOOL:
      return boolValue(value);
    case TypeCode.INT64:
    case TypeCode.ENUM:
      return stringValue(value);
    case TypeCode.FLOAT32:
    case TypeCode.FLOAT64: {
      const kind = valueKind(value);
      if (kind === 'string') {
        const s = stringValue(value);
        if (s === 'NaN') return Number.NaN;
        if (s === 'Infinity') return Infinity;
        if (s === '-Infinity') return -Infinity;
      }
      return numberValue(value);
    }
    case TypeCode.BYTES:
    case TypeCode.PROTO:
      return Buffer.from(stringValue(value), 'base64');
    case TypeCode.STRING:
    case TypeCode.TIMESTAMP:
    case TypeCode.DATE:
    case TypeCode.NUMERIC:
    case TypeCode.INTERVAL:
    case TypeCode.UUID:
    case TypeCode.JSON:
      return stringValue(value);
    case TypeCode.ARRAY: {
      const elemType = arrayElementType(typ);
      return listValues(value).map((elem) => wireToNative(elemType, elem));
    }
    case TypeCode.STRUCT: {
      const fields = structFields(typ);
      return listValues(value).map((elem, i) => wireToNative(fieldType(fields[i]), elem));
    }
    default:
      return value;
  }
}

const conformance = loadConformance();

describe('encodeValue round-trip', () => {
  for (const testCase of conformance.value_cases) {
    it(testCase.name, () => {
      const native = wireToNative(testCase.type, testCase.value);
      const encoded = encodeValue(testCase.type, native);
      wireEqual(encoded, testCase.value);
      assert.equal(
        formatValue(testCase.type, encoded, simpleFormatConfig()),
        testCase.expected.simple,
        `format after encode for ${testCase.name}`,
      );
    });
  }
});

describe('encodeValue struct hash', () => {
  it('encodes named struct from object', () => {
    const typ = {
      code: 'STRUCT',
      structType: {
        fields: [
          { name: 'n', type: { code: 'INT64' } },
          { name: 's', type: { code: 'STRING' } },
        ],
      },
    };
    const encoded = encodeValue(typ, { n: '1', s: 'foo' });
    wireEqual(encoded, ['1', 'foo']);
    assert.equal(
      formatValue(typ, encoded, literalFormatConfig()),
      'STRUCT<n INT64, s STRING>(1, "foo")',
    );
  });
});

describe('adaptClientType', () => {
  it('adapts camelCase client shapes', () => {
    const client = {
      code: 'ARRAY',
      arrayElementType: { code: 'INT64' },
    };
    assert.deepEqual(adaptClientType(client), {
      code: 'ARRAY',
      arrayElementType: { code: 'INT64' },
    });
  });

  it('adapts nested struct fields', () => {
    const client = {
      code: 'STRUCT',
      structType: {
        fields: [
          { name: 'n', type: { code: 'INT64' } },
          { name: 's', type: { code: 'STRING' } },
        ],
      },
    };
    assert.deepEqual(adaptClientType(client), {
      code: 'STRUCT',
      structType: {
        fields: [
          { name: 'n', type: { code: 'INT64' } },
          { name: 's', type: { code: 'STRING' } },
        ],
      },
    });
  });
});

describe('formatResultRow', () => {
  it('formats a row from native values', () => {
    const types = [
      { code: 'INT64' },
      { code: 'STRING' },
      {
        code: 'STRUCT',
        structType: {
          fields: [
            { name: 'n', type: { code: 'INT64' } },
            { name: 's', type: { code: 'STRING' } },
          ],
        },
      },
    ];
    const values = [42, null, { n: '7', s: 'x' }];
    const got = formatResultRow(types, values, simpleFormatConfig());
    assert.deepEqual(got, ['42', '<null>', '(7 AS n, x AS s)']);
  });
});
