"""Encode native values to wire google.protobuf.Value dicts."""

from __future__ import annotations

import base64
import json
import math
from typing import Any

from .codes import TypeAnnotationCode, TypeCode
from .errors import MismatchedFieldsError, SpanValueError
from .proto import (
    array_element_type,
    field_name,
    field_type,
    struct_fields,
    type_annotation,
    type_code,
)


def _null_value() -> dict[str, Any]:
    return {"null_value": None}


def _bool_value(v: bool) -> dict[str, Any]:
    return {"bool_value": v}


def _string_value(v: str) -> dict[str, Any]:
    return {"string_value": v}


def _number_value(v: float) -> dict[str, Any]:
    return {"number_value": v}


def _list_value(values: list[dict[str, Any]]) -> dict[str, Any]:
    return {"list_value": {"values": values}}


def _encode_float(v: float, *, narrow: bool = False) -> dict[str, Any]:
    if narrow:
        from .float_fmt import narrow_float32

        v = narrow_float32(v)
    if math.isnan(v):
        return _string_value("NaN")
    if math.isinf(v):
        return _string_value("Infinity" if v > 0 else "-Infinity")
    return _number_value(v)


def _encode_json_string(native_value: Any) -> str:
    if isinstance(native_value, str):
        return native_value
    return json.dumps(native_value, separators=(",", ":"), ensure_ascii=False)


def encode_value(typ: Any, native_value: Any) -> dict[str, Any]:
    """Encode a native value to a wire ``google.protobuf.Value`` dict."""
    code = type_code(typ)

    if native_value is None:
        return _null_value()

    if code == TypeCode.BOOL:
        return _bool_value(bool(native_value))

    if code == TypeCode.INT64:
        return _string_value(str(int(native_value)))

    if code == TypeCode.ENUM:
        return _string_value(str(int(native_value)))

    if code == TypeCode.FLOAT64:
        return _encode_float(float(native_value))

    if code == TypeCode.FLOAT32:
        return _encode_float(float(native_value), narrow=True)

    if code in (
        TypeCode.STRING,
        TypeCode.TIMESTAMP,
        TypeCode.DATE,
        TypeCode.NUMERIC,
        TypeCode.INTERVAL,
        TypeCode.UUID,
    ):
        return _string_value(str(native_value))

    if code == TypeCode.JSON:
        return _string_value(_encode_json_string(native_value))

    if code == TypeCode.BYTES or code == TypeCode.PROTO:
        if not isinstance(native_value, (bytes, bytearray)):
            raise TypeError(
                f"{TypeCode(code).name} native value must be bytes, got {type(native_value)!r}"
            )
        return _string_value(base64.standard_b64encode(native_value).decode("ascii"))

    if code == TypeCode.ARRAY:
        elem_type = array_element_type(typ)
        if not isinstance(native_value, (list, tuple)):
            raise TypeError("ARRAY native value must be list or tuple")
        return _list_value([encode_value(elem_type, item) for item in native_value])

    if code == TypeCode.STRUCT:
        fields = struct_fields(typ)
        if isinstance(native_value, dict):
            encoded_fields = []
            for field in fields:
                name = field_name(field)
                if name not in native_value:
                    raise MismatchedFieldsError(
                        f"STRUCT dict missing field {name!r}"
                    )
                encoded_fields.append(
                    encode_value(field_type(field), native_value[name])
                )
        elif isinstance(native_value, (list, tuple)):
            if len(native_value) != len(fields):
                raise MismatchedFieldsError(
                    f"STRUCT field count mismatch: got {len(native_value)}, want {len(fields)}"
                )
            encoded_fields = [
                encode_value(field_type(field), item)
                for field, item in zip(fields, native_value)
            ]
        else:
            raise TypeError("STRUCT native value must be list, tuple, or dict")
        return _list_value(encoded_fields)

    ann = type_annotation(typ)
    if code == TypeCode.NUMERIC and ann == TypeAnnotationCode.PG_NUMERIC:
        return _string_value(str(native_value))
    if ann == TypeAnnotationCode.PG_JSONB:
        return _string_value(_encode_json_string(native_value))
    if ann == TypeAnnotationCode.PG_OID:
        return _string_value(str(int(native_value)))

    raise SpanValueError(f"unsupported type code for encoding: {code}")


def _type_to_wire_dict(typ: Any) -> dict[str, Any]:
    if isinstance(typ, dict):
        return typ
    code = getattr(typ, "code", None)
    if code is None:
        code = getattr(typ, "Code", None)
    if code is None:
        raise TypeError(f"cannot adapt client type {type(typ)!r}")

    out: dict[str, Any] = {"code": _enum_name(code)}
    ann = getattr(typ, "type_annotation", None)
    if ann is None:
        ann = getattr(typ, "typeAnnotation", None)
    if ann is None:
        ann = getattr(typ, "TypeAnnotation", None)
    if ann is not None:
        ann_name = _enum_name(ann)
        if ann_name != "TYPE_ANNOTATION_CODE_UNSPECIFIED":
            out["type_annotation"] = ann_name

    fqn = getattr(typ, "proto_type_fqn", None)
    if fqn is None:
        fqn = getattr(typ, "protoTypeFqn", None)
    if fqn is None:
        fqn = getattr(typ, "ProtoTypeFqn", None)
    if fqn:
        out["proto_type_fqn"] = fqn

    elem = getattr(typ, "array_element_type", None)
    if elem is None:
        elem = getattr(typ, "arrayElementType", None)
    if elem is None:
        elem = getattr(typ, "arrayElementType", None)
    if elem:
        out["array_element_type"] = _type_to_wire_dict(elem)

    struct_type = getattr(typ, "struct_type", None)
    if struct_type is None:
        struct_type = getattr(typ, "structType", None)
    if struct_type is None:
        struct_type = getattr(typ, "StructType", None)
    if struct_type:
        fields = getattr(struct_type, "fields", None)
        if fields is None:
            fields = getattr(struct_type, "Fields", [])
        out["struct_type"] = {
            "fields": [
                {"name": field_name(field), "type": _type_to_wire_dict(field_type(field))}
                for field in fields
            ]
        }
        return out

    get_struct_fields = getattr(typ, "getStructFields", None)
    if callable(get_struct_fields):
        fields = get_struct_fields()
        out["struct_type"] = {
            "fields": [
                {
                    "name": getattr(field, "name", None) or getattr(field, "getName", lambda: "")(),
                    "type": _type_to_wire_dict(
                        getattr(field, "type", None) or getattr(field, "getType", lambda: None)()
                    ),
                }
                for field in fields
            ]
        }

    return out


def _enum_name(value: Any) -> str:
    if isinstance(value, str):
        return value
    if isinstance(value, int):
        from .codes import TYPE_ANNOTATION_NAMES, TYPE_CODE_NAMES

        return TYPE_CODE_NAMES.get(value) or TYPE_ANNOTATION_NAMES.get(value) or str(value)
    if hasattr(value, "name"):
        return str(value.name)
    return str(value)


def adapt_client_type(client_type: Any) -> dict[str, Any]:
    """Adapt a ``google.cloud.spanner`` client type to a wire ``Type`` dict.

  Requires ``google-cloud-spanner`` only when adapting high-level client types;
  wire ``google.spanner.v1.Type`` objects and protojson dicts pass through.
  """
    try:
        from google.cloud.spanner_v1.types import Type as WireType  # noqa: F401
    except ImportError:
        pass

    if isinstance(client_type, dict):
        return client_type

    module = type(client_type).__module__
    if module.startswith("google.cloud.spanner_v1"):
        return _type_to_wire_dict(client_type)

    if type(client_type).__name__ == "Type" and module == "google.cloud.spanner":
        return _adapt_cloud_spanner_type(client_type)

    return _type_to_wire_dict(client_type)


def _adapt_cloud_spanner_type(client_type: Any) -> dict[str, Any]:
    code_obj = client_type.getCode() if hasattr(client_type, "getCode") else client_type.code()
    wire_code = getattr(code_obj, "getTypeCode", lambda: None)()
    if wire_code is None:
        wire_code = getattr(code_obj, "get_type_code", lambda: None)()
    if wire_code is None:
        raise TypeError(f"cannot map client type code {code_obj!r}")

    out: dict[str, Any] = {"code": _enum_name(wire_code)}
    ann = getattr(code_obj, "getTypeAnnotationCode", lambda: None)()
    if ann is None:
        ann = getattr(code_obj, "get_type_annotation_code", lambda: None)()
    if ann is not None:
        ann_name = _enum_name(ann)
        if ann_name != "TYPE_ANNOTATION_CODE_UNSPECIFIED":
            out["type_annotation"] = ann_name

    fqn = None
    if hasattr(client_type, "getProtoTypeFqn"):
        code_name = _enum_name(wire_code)
        if code_name in ("PROTO", "ENUM"):
            fqn = client_type.getProtoTypeFqn()
    if fqn:
        out["proto_type_fqn"] = fqn

    if _enum_name(wire_code) == "ARRAY" and hasattr(client_type, "getArrayElementType"):
        elem = client_type.getArrayElementType()
        if elem is not None:
            out["array_element_type"] = _adapt_cloud_spanner_type(elem)

    if _enum_name(wire_code) == "STRUCT" and hasattr(client_type, "getStructFields"):
        fields = client_type.getStructFields()
        out["struct_type"] = {
            "fields": [
                {
                    "name": field.getName(),
                    "type": _adapt_cloud_spanner_type(field.getType()),
                }
                for field in fields
            ]
        }

    return out


def format_result_row(
    types: list[Any],
    values: list[Any],
    config: Any,
) -> list[str]:
    """Encode native column values, then format each cell."""
    from .format_config import format_row

    if len(types) != len(values):
        raise ValueError(f"len(types)={len(types)} != len(values)={len(values)}")
    encoded = [encode_value(typ, value) for typ, value in zip(types, values)]
    return format_row(types, encoded, config)
