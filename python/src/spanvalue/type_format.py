"""Format Cloud Spanner google.spanner.v1.Type values."""

from __future__ import annotations

from dataclasses import dataclass, replace
from enum import IntEnum

from .codes import (
    TYPE_ANNOTATION_NAMES,
    TypeAnnotationCode,
    TypeCode,
    parse_type_annotation,
    type_code_name,
)
from .errors import UnknownTypeError
from .proto import (
    array_element_type,
    field_name,
    field_type,
    proto_type_fqn,
    struct_fields,
    type_annotation,
    type_code,
)


class StructMode(IntEnum):
    BASE = 0
    RECURSIVE = 1
    RECURSIVE_WITH_NAME = 2


class ProtoEnumMode(IntEnum):
    BASE = 0
    LEAF = 1
    FULL = 2
    LEAF_WITH_KIND = 3
    FULL_WITH_KIND = 4


class ArrayMode(IntEnum):
    BASE = 0
    RECURSIVE = 1


class UnknownMode(IntEnum):
    UNKNOWN = 0
    TYPE_CODE = 1
    VERBOSE = 2
    PANIC = 3


class TypeAnnotationMode(IntEnum):
    SUFFIX = 0
    OMIT = 1
    PRIMARY = 2


@dataclass(frozen=True)
class FormatOption:
    struct: StructMode = StructMode.BASE
    proto: ProtoEnumMode = ProtoEnumMode.BASE
    enum: ProtoEnumMode = ProtoEnumMode.BASE
    array: ArrayMode = ArrayMode.BASE
    unknown: UnknownMode = UnknownMode.UNKNOWN
    type_annotation: TypeAnnotationMode = TypeAnnotationMode.SUFFIX


FORMAT_OPTION_SIMPLEST = FormatOption(
    struct=StructMode.BASE,
    proto=ProtoEnumMode.BASE,
    enum=ProtoEnumMode.BASE,
    array=ArrayMode.BASE,
    unknown=UnknownMode.TYPE_CODE,
)

FORMAT_OPTION_SIMPLE = FormatOption(
    struct=StructMode.BASE,
    proto=ProtoEnumMode.LEAF,
    enum=ProtoEnumMode.LEAF,
    array=ArrayMode.RECURSIVE,
    unknown=UnknownMode.UNKNOWN,
)

FORMAT_OPTION_NORMAL = FormatOption(
    struct=StructMode.RECURSIVE,
    proto=ProtoEnumMode.LEAF,
    enum=ProtoEnumMode.LEAF,
    array=ArrayMode.RECURSIVE,
    unknown=UnknownMode.VERBOSE,
)

FORMAT_OPTION_VERBOSE = FormatOption(
    struct=StructMode.RECURSIVE_WITH_NAME,
    proto=ProtoEnumMode.FULL,
    enum=ProtoEnumMode.FULL,
    array=ArrayMode.RECURSIVE,
    unknown=UnknownMode.VERBOSE,
)

FORMAT_OPTION_MORE_VERBOSE = FormatOption(
    struct=StructMode.RECURSIVE_WITH_NAME,
    proto=ProtoEnumMode.FULL_WITH_KIND,
    enum=ProtoEnumMode.FULL_WITH_KIND,
    array=ArrayMode.RECURSIVE,
    unknown=UnknownMode.VERBOSE,
)


def _last_cut(s: str, sep: str) -> str:
    if sep in s:
        return s.rsplit(sep, 1)[-1]
    return s


def _annotation_suffix(ann: int) -> str:
    if ann == TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED:
        return ""
    name = TYPE_ANNOTATION_NAMES.get(ann)
    if name is None:
        return f"({ann})"
    return f"({name})"


def _annotation_name(ann: int) -> str:
    name = TYPE_ANNOTATION_NAMES.get(ann)
    if name is None:
        return str(ann)
    return name


def format_type_code(code: int, mode: UnknownMode = UnknownMode.VERBOSE) -> str:
    name = type_code_name(code)
    if name is not None:
        return name
    if mode == UnknownMode.TYPE_CODE:
        return str(code)
    if mode == UnknownMode.VERBOSE:
        return f"UNKNOWN({code})"
    if mode == UnknownMode.PANIC:
        raise UnknownTypeError(f"unknown TypeCode({code})")
    return "UNKNOWN"


def format_proto_enum(typ: object, mode: ProtoEnumMode) -> str:
    code = type_code(typ)
    fqn = proto_type_fqn(typ)
    code_name = format_type_code(code)
    if mode == ProtoEnumMode.LEAF:
        return _last_cut(fqn, ".")
    if mode == ProtoEnumMode.FULL:
        return fqn
    if mode == ProtoEnumMode.LEAF_WITH_KIND:
        return f"{code_name}<{_last_cut(fqn, '.')}>"
    if mode == ProtoEnumMode.FULL_WITH_KIND:
        return f"{code_name}<{fqn}>"
    return code_name


def format_struct_fields(fields: list[object], option: FormatOption) -> str:
    parts: list[str] = []
    for field in fields:
        type_str = format_type(field_type(field), option)
        if option.struct == StructMode.RECURSIVE_WITH_NAME and field_name(field):
            parts.append(f"{field_name(field)} {type_str}")
        else:
            parts.append(type_str)
    return ", ".join(parts)


def _format_type_impl(typ: object, option: FormatOption) -> str:
    code = type_code(typ)
    if code == TypeCode.ARRAY and option.array != ArrayMode.BASE:
        elem = array_element_type(typ)
        return f"ARRAY<{format_type(elem, option)}>"
    if code == TypeCode.PROTO:
        return format_proto_enum(typ, option.proto)
    if code == TypeCode.ENUM:
        return format_proto_enum(typ, option.enum)
    if code == TypeCode.STRUCT and option.struct != StructMode.BASE:
        return f"STRUCT<{format_struct_fields(struct_fields(typ), option)}>"
    return format_type_code(code, option.unknown)


def format_type(typ: object, option: FormatOption | None = None) -> str:
    """Format a Cloud Spanner Type as a string."""
    if option is None:
        option = FORMAT_OPTION_SIMPLE
    ann = type_annotation(typ)
    if option.type_annotation == TypeAnnotationMode.OMIT:
        return _format_type_impl(typ, option)
    if option.type_annotation == TypeAnnotationMode.PRIMARY:
        if ann != TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED:
            return _annotation_name(ann)
        return _format_type_impl(typ, option)
    return _format_type_impl(typ, option) + _annotation_suffix(ann)


def format_type_simplest(typ: object) -> str:
    return format_type(typ, FORMAT_OPTION_SIMPLEST)


def format_type_simple(typ: object) -> str:
    return format_type(typ, FORMAT_OPTION_SIMPLE)


def format_type_normal(typ: object) -> str:
    return format_type(typ, FORMAT_OPTION_NORMAL)


def format_type_verbose(typ: object) -> str:
    return format_type(typ, FORMAT_OPTION_VERBOSE)


def format_type_more_verbose(typ: object) -> str:
    return format_type(typ, FORMAT_OPTION_MORE_VERBOSE)


def format_type_verbose_annotation_omit(typ: object) -> str:
    return format_type(typ, replace(FORMAT_OPTION_VERBOSE, type_annotation=TypeAnnotationMode.OMIT))


def format_type_verbose_annotation_primary(typ: object) -> str:
    return format_type(typ, replace(FORMAT_OPTION_VERBOSE, type_annotation=TypeAnnotationMode.PRIMARY))
