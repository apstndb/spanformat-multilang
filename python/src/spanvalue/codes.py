"""Spanner TypeCode and TypeAnnotationCode constants."""

from __future__ import annotations

from enum import IntEnum


class TypeCode(IntEnum):
    TYPE_CODE_UNSPECIFIED = 0
    BOOL = 1
    INT64 = 2
    FLOAT64 = 3
    FLOAT32 = 4
    TIMESTAMP = 5
    DATE = 6
    STRING = 7
    BYTES = 8
    ARRAY = 9
    STRUCT = 10
    NUMERIC = 11
    JSON = 12
    PROTO = 13
    ENUM = 14
    INTERVAL = 15
    UUID = 16


TYPE_CODE_NAMES: dict[int, str] = {member.value: member.name for member in TypeCode}


class TypeAnnotationCode(IntEnum):
    TYPE_ANNOTATION_CODE_UNSPECIFIED = 0
    PG_NUMERIC = 2
    PG_JSONB = 3
    PG_OID = 4


TYPE_ANNOTATION_NAMES: dict[int, str] = {
    member.value: member.name for member in TypeAnnotationCode
}


def parse_type_code(value: object) -> int:
    """Accept enum name or numeric code from protojson."""
    if value is None:
        return TypeCode.TYPE_CODE_UNSPECIFIED
    if isinstance(value, int):
        return value
    if isinstance(value, str):
        if value.isdigit() or (value.startswith("-") and value[1:].isdigit()):
            return int(value)
        return int(TypeCode[value])
    if hasattr(value, "name"):
        return int(TypeCode[value.name])
    if hasattr(value, "value"):
        return int(value.value)
    raise TypeError(f"cannot parse type code from {value!r}")


def parse_type_annotation(value: object) -> int:
    """Accept enum name or numeric annotation from protojson."""
    if value is None:
        return TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED
    if isinstance(value, int):
        return value
    if isinstance(value, str):
        if value.isdigit() or (value.startswith("-") and value[1:].isdigit()):
            return int(value)
        return int(TypeAnnotationCode[value])
    if hasattr(value, "name"):
        return int(TypeAnnotationCode[value.name])
    if hasattr(value, "value"):
        return int(value.value)
    raise TypeError(f"cannot parse type annotation from {value!r}")


def type_code_name(code: int) -> str | None:
    return TYPE_CODE_NAMES.get(code)
