/** Format Cloud Spanner google.spanner.v1.Type values. */

import {
  TYPE_ANNOTATION_NAMES,
  TypeAnnotationCode,
  TypeCode,
  typeCodeName,
} from './codes.js';
import { UnknownTypeError } from './errors.js';
import {
  arrayElementType,
  fieldName,
  fieldType,
  protoTypeFqn,
  structFields,
  typeAnnotation,
  typeCode,
} from './proto.js';

export const StructMode = Object.freeze({
  BASE: 0,
  RECURSIVE: 1,
  RECURSIVE_WITH_NAME: 2,
});

export const ProtoEnumMode = Object.freeze({
  BASE: 0,
  LEAF: 1,
  FULL: 2,
  LEAF_WITH_KIND: 3,
  FULL_WITH_KIND: 4,
});

export const ArrayMode = Object.freeze({
  BASE: 0,
  RECURSIVE: 1,
});

export const UnknownMode = Object.freeze({
  UNKNOWN: 0,
  TYPE_CODE: 1,
  VERBOSE: 2,
  PANIC: 3,
});

export const TypeAnnotationMode = Object.freeze({
  SUFFIX: 0,
  OMIT: 1,
  PRIMARY: 2,
});

/** @typedef {Object} FormatOption
 * @property {number} [struct]
 * @property {number} [proto]
 * @property {number} [enum]
 * @property {number} [array]
 * @property {number} [unknown]
 * @property {number} [typeAnnotation]
 */

export const FORMAT_OPTION_SIMPLEST = Object.freeze({
  struct: StructMode.BASE,
  proto: ProtoEnumMode.BASE,
  enum: ProtoEnumMode.BASE,
  array: ArrayMode.BASE,
  unknown: UnknownMode.TYPE_CODE,
  typeAnnotation: TypeAnnotationMode.SUFFIX,
});

export const FORMAT_OPTION_SIMPLE = Object.freeze({
  struct: StructMode.BASE,
  proto: ProtoEnumMode.LEAF,
  enum: ProtoEnumMode.LEAF,
  array: ArrayMode.RECURSIVE,
  unknown: UnknownMode.UNKNOWN,
  typeAnnotation: TypeAnnotationMode.SUFFIX,
});

export const FORMAT_OPTION_NORMAL = Object.freeze({
  struct: StructMode.RECURSIVE,
  proto: ProtoEnumMode.LEAF,
  enum: ProtoEnumMode.LEAF,
  array: ArrayMode.RECURSIVE,
  unknown: UnknownMode.VERBOSE,
  typeAnnotation: TypeAnnotationMode.SUFFIX,
});

export const FORMAT_OPTION_VERBOSE = Object.freeze({
  struct: StructMode.RECURSIVE_WITH_NAME,
  proto: ProtoEnumMode.FULL,
  enum: ProtoEnumMode.FULL,
  array: ArrayMode.RECURSIVE,
  unknown: UnknownMode.VERBOSE,
  typeAnnotation: TypeAnnotationMode.SUFFIX,
});

export const FORMAT_OPTION_MORE_VERBOSE = Object.freeze({
  struct: StructMode.RECURSIVE_WITH_NAME,
  proto: ProtoEnumMode.FULL_WITH_KIND,
  enum: ProtoEnumMode.FULL_WITH_KIND,
  array: ArrayMode.RECURSIVE,
  unknown: UnknownMode.VERBOSE,
  typeAnnotation: TypeAnnotationMode.SUFFIX,
});

/**
 * @param {string} s
 * @param {string} sep
 */
function lastCut(s, sep) {
  const idx = s.lastIndexOf(sep);
  return idx >= 0 ? s.slice(idx + sep.length) : s;
}

/**
 * @param {number} ann
 */
function annotationSuffix(ann) {
  if (ann === TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED) {
    return '';
  }
  const name = TYPE_ANNOTATION_NAMES[ann];
  if (name == null) {
    return `(${ann})`;
  }
  return `(${name})`;
}

/**
 * @param {number} ann
 */
function annotationName(ann) {
  const name = TYPE_ANNOTATION_NAMES[ann];
  if (name == null) {
    return String(ann);
  }
  return name;
}

/**
 * @param {number} code
 * @param {number} [mode]
 */
export function formatTypeCode(code, mode = UnknownMode.VERBOSE) {
  const name = typeCodeName(code);
  if (name != null) {
    return name;
  }
  if (mode === UnknownMode.TYPE_CODE) {
    return String(code);
  }
  if (mode === UnknownMode.VERBOSE) {
    return `UNKNOWN(${code})`;
  }
  if (mode === UnknownMode.PANIC) {
    throw new UnknownTypeError(`unknown TypeCode(${code})`);
  }
  return 'UNKNOWN';
}

/**
 * @param {unknown} typ
 * @param {number} mode
 */
export function formatProtoEnum(typ, mode) {
  const code = typeCode(typ);
  const fqn = protoTypeFqn(typ);
  const codeName = formatTypeCode(code);
  if (mode === ProtoEnumMode.LEAF) {
    return lastCut(fqn, '.');
  }
  if (mode === ProtoEnumMode.FULL) {
    return fqn;
  }
  if (mode === ProtoEnumMode.LEAF_WITH_KIND) {
    return `${codeName}<${lastCut(fqn, '.')}>`;
  }
  if (mode === ProtoEnumMode.FULL_WITH_KIND) {
    return `${codeName}<${fqn}>`;
  }
  return codeName;
}

/**
 * @param {unknown[]} fields
 * @param {FormatOption} option
 */
export function formatStructFields(fields, option) {
  const parts = [];
  for (const field of fields) {
    const typeStr = formatType(fieldType(field), option);
    if (option.struct === StructMode.RECURSIVE_WITH_NAME && fieldName(field)) {
      parts.push(`${fieldName(field)} ${typeStr}`);
    } else {
      parts.push(typeStr);
    }
  }
  return parts.join(', ');
}

/**
 * @param {unknown} typ
 * @param {FormatOption} option
 */
function formatTypeImpl(typ, option) {
  const code = typeCode(typ);
  if (code === TypeCode.ARRAY && option.array !== ArrayMode.BASE) {
    const elem = arrayElementType(typ);
    return `ARRAY<${formatType(elem, option)}>`;
  }
  if (code === TypeCode.PROTO) {
    return formatProtoEnum(typ, option.proto ?? ProtoEnumMode.BASE);
  }
  if (code === TypeCode.ENUM) {
    return formatProtoEnum(typ, option.enum ?? ProtoEnumMode.BASE);
  }
  if (code === TypeCode.STRUCT && option.struct !== StructMode.BASE) {
    return `STRUCT<${formatStructFields(structFields(typ), option)}>`;
  }
  return formatTypeCode(code, option.unknown ?? UnknownMode.VERBOSE);
}

/**
 * Format a Cloud Spanner Type as a string.
 * @param {unknown} typ
 * @param {FormatOption | null | undefined} [option]
 */
export function formatType(typ, option = FORMAT_OPTION_SIMPLE) {
  const opt = option ?? FORMAT_OPTION_SIMPLE;
  const ann = typeAnnotation(typ);
  const annMode = opt.typeAnnotation ?? TypeAnnotationMode.SUFFIX;
  if (annMode === TypeAnnotationMode.OMIT) {
    return formatTypeImpl(typ, opt);
  }
  if (annMode === TypeAnnotationMode.PRIMARY) {
    if (ann !== TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED) {
      return annotationName(ann);
    }
    return formatTypeImpl(typ, opt);
  }
  return formatTypeImpl(typ, opt) + annotationSuffix(ann);
}

/** @param {unknown} typ */
export function formatTypeSimplest(typ) {
  return formatType(typ, FORMAT_OPTION_SIMPLEST);
}

/** @param {unknown} typ */
export function formatTypeSimple(typ) {
  return formatType(typ, FORMAT_OPTION_SIMPLE);
}

/** @param {unknown} typ */
export function formatTypeNormal(typ) {
  return formatType(typ, FORMAT_OPTION_NORMAL);
}

/** @param {unknown} typ */
export function formatTypeVerbose(typ) {
  return formatType(typ, FORMAT_OPTION_VERBOSE);
}

/** @param {unknown} typ */
export function formatTypeMoreVerbose(typ) {
  return formatType(typ, FORMAT_OPTION_MORE_VERBOSE);
}

/** @param {unknown} typ */
export function formatTypeVerboseAnnotationOmit(typ) {
  return formatType(typ, {
    ...FORMAT_OPTION_VERBOSE,
    typeAnnotation: TypeAnnotationMode.OMIT,
  });
}

/** @param {unknown} typ */
export function formatTypeVerboseAnnotationPrimary(typ) {
  return formatType(typ, {
    ...FORMAT_OPTION_VERBOSE,
    typeAnnotation: TypeAnnotationMode.PRIMARY,
  });
}
