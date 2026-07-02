/** Duck-typed adapters for @google-cloud/spanner structural types. */

import {
  TypeAnnotationCode,
  TYPE_ANNOTATION_NAMES,
  typeCodeName,
} from './codes.js';
import {
  arrayElementType,
  fieldName,
  fieldType,
  protoTypeFqn,
  structFields,
  structType,
  typeAnnotation,
  typeCode,
} from './proto.js';

/**
 * @param {unknown} clientType
 * @returns {Record<string, unknown>}
 */
export function adaptClientType(clientType) {
  if (clientType == null) {
    return { code: 'TYPE_CODE_UNSPECIFIED' };
  }

  const code = typeCode(clientType);
  const name = typeCodeName(code);
  /** @type {Record<string, unknown>} */
  const wire = { code: name ?? code };

  const ann = typeAnnotation(clientType);
  if (ann != null && ann !== TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED) {
    wire.typeAnnotation = TYPE_ANNOTATION_NAMES[ann] ?? ann;
  }

  const fqn = protoTypeFqn(clientType);
  if (fqn) {
    wire.protoTypeFqn = fqn;
  }

  const elem = arrayElementType(clientType);
  if (elem != null) {
    wire.arrayElementType = adaptClientType(elem);
  }

  const st = structType(clientType);
  if (st != null) {
    wire.structType = {
      fields: structFields(clientType).map((field) => {
        /** @type {{ type: Record<string, unknown>, name?: string }} */
        const out = { type: adaptClientType(fieldType(field)) };
        const name = fieldName(field);
        if (name) {
          out.name = name;
        }
        return out;
      }),
    };
  }

  return wire;
}
