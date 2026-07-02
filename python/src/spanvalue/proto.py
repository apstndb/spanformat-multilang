"""Duck-typed access to protojson dicts and protobuf objects."""

from __future__ import annotations

from typing import Any

from .codes import parse_type_annotation, parse_type_code


def _get(obj: Any, *names: str, default: Any = None) -> Any:
    if obj is None:
        return default
    if isinstance(obj, dict):
        for name in names:
            if name in obj:
                return obj[name]
        return default
    for name in names:
        if hasattr(obj, name):
            return getattr(obj, name)
    getter = getattr(obj, "get", None)
    if getter is not None:
        for name in names:
            val = getter(name)
            if val is not None:
                return val
    return default


def type_code(typ: Any) -> int:
    return parse_type_code(_get(typ, "code", "Code"))


def type_annotation(typ: Any) -> int:
    return parse_type_annotation(_get(typ, "type_annotation", "typeAnnotation", "TypeAnnotation"))


def proto_type_fqn(typ: Any) -> str:
    return _get(typ, "proto_type_fqn", "protoTypeFqn", "ProtoTypeFqn", default="") or ""


def array_element_type(typ: Any) -> Any:
    return _get(typ, "array_element_type", "arrayElementType", "ArrayElementType")


def struct_type(typ: Any) -> Any:
    return _get(typ, "struct_type", "structType", "StructType")


def struct_fields(typ: Any) -> list[Any]:
    st = struct_type(typ)
    if st is None:
        return []
    fields = _get(st, "fields", "Fields", default=[])
    return list(fields) if fields is not None else []


def field_name(field: Any) -> str:
    return _get(field, "name", "Name", default="") or ""


def field_type(field: Any) -> Any:
    return _get(field, "type", "Type")


def value_kind(value: Any) -> str:
    """Return wire kind: null, bool, number, string, list, or missing."""
    if value is None:
        return "null"
    if isinstance(value, dict):
        if "null_value" in value or "nullValue" in value:
            return "null"
        if "bool_value" in value or "boolValue" in value:
            return "bool"
        if "number_value" in value or "numberValue" in value:
            return "number"
        if "string_value" in value or "stringValue" in value:
            return "string"
        if "list_value" in value or "listValue" in value:
            return "list"
        return "missing"
    if isinstance(value, bool):
        return "bool"
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "list"
    if hasattr(value, "WhichOneof"):
        which = value.WhichOneof("kind")
        if which is None:
            return "missing"
        if which in ("null_value", "nullValue"):
            return "null"
        if which in ("bool_value", "boolValue"):
            return "bool"
        if which in ("number_value", "numberValue"):
            return "number"
        if which in ("string_value", "stringValue"):
            return "string"
        if which in ("list_value", "listValue"):
            return "list"
    for attr, kind in (
        ("bool_value", "bool"),
        ("boolValue", "bool"),
        ("number_value", "number"),
        ("numberValue", "number"),
        ("string_value", "string"),
        ("stringValue", "string"),
        ("list_value", "list"),
        ("listValue", "list"),
    ):
        if hasattr(value, attr):
            if getattr(value, attr) is not None or kind == "null":
                return kind
    return "missing"


def is_null_value(value: Any) -> bool:
    return value_kind(value) in ("null", "missing")


def bool_value(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, dict):
        return bool(value.get("bool_value", value.get("boolValue")))
    return bool(_get(value, "bool_value", "boolValue"))


def number_value(value: Any) -> float:
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return float(value)
    if isinstance(value, dict):
        return float(value.get("number_value", value.get("numberValue")))
    return float(_get(value, "number_value", "numberValue"))


def string_value(value: Any) -> str:
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        return str(value.get("string_value", value.get("stringValue", "")))
    return str(_get(value, "string_value", "stringValue", default=""))


def list_values(value: Any) -> list[Any]:
    if isinstance(value, list):
        return value
    if isinstance(value, dict):
        lv = value.get("list_value", value.get("listValue"))
        if isinstance(lv, dict):
            return list(lv.get("values", lv.get("Values", [])))
        if lv is not None and hasattr(lv, "values"):
            return list(lv.values)
    lv = _get(value, "list_value", "listValue")
    if lv is not None:
        vals = _get(lv, "values", "Values", default=[])
        return list(vals) if vals is not None else []
    return []
