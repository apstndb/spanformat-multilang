export const TypeCode = {
  TYPE_CODE_UNSPECIFIED: 0, BOOL: 1, INT64: 2, FLOAT64: 3, FLOAT32: 4,
  TIMESTAMP: 5, DATE: 6, STRING: 7, BYTES: 8, ARRAY: 9, STRUCT: 10,
  NUMERIC: 11, JSON: 12, PROTO: 13, ENUM: 14, INTERVAL: 15, UUID: 16,
};

export const TYPE_CODE_NAMES = {
  0: "TYPE_CODE_UNSPECIFIED", 1: "BOOL", 2: "INT64", 3: "FLOAT64", 4: "FLOAT32",
  5: "TIMESTAMP", 6: "DATE", 7: "STRING", 8: "BYTES", 9: "ARRAY", 10: "STRUCT",
  11: "NUMERIC", 12: "JSON", 13: "PROTO", 14: "ENUM", 15: "INTERVAL", 16: "UUID",
};

export const TypeAnnotationCode = {
  TYPE_ANNOTATION_CODE_UNSPECIFIED: 0, PG_NUMERIC: 2, PG_JSONB: 3, PG_OID: 4,
};

export const TYPE_ANNOTATION_NAMES = {
  0: "TYPE_ANNOTATION_CODE_UNSPECIFIED", 2: "PG_NUMERIC", 3: "PG_JSONB", 4: "PG_OID",
};

export function parseTypeCode(value) {
  if (value == null) return TypeCode.TYPE_CODE_UNSPECIFIED;
  if (typeof value === "number") return value;
  if (typeof value === "string") {
    if (/^-?\d+$/.test(value)) return Number(value);
    return TypeCode[value];
  }
  if (typeof value === "object") {
    if ("name" in value) return TypeCode[value.name];
    if ("value" in value) return value.value;
  }
  throw new TypeError(`cannot parse type code from ${String(value)}`);
}

export function parseTypeAnnotation(value) {
  if (value == null) return TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED;
  if (typeof value === "number") return value;
  if (typeof value === "string") {
    if (/^-?\d+$/.test(value)) return Number(value);
    return TypeAnnotationCode[value];
  }
  if (typeof value === "object") {
    if ("name" in value) return TypeAnnotationCode[value.name];
    if ("value" in value) return value.value;
  }
  throw new TypeError(`cannot parse type annotation from ${String(value)}`);
}

export function typeCodeName(code) {
  return TYPE_CODE_NAMES[code];
}
