"""FormatConfig presets and value formatting."""

from __future__ import annotations

from dataclasses import dataclass, replace
from enum import IntEnum

from .codes import TypeCode
from .errors import (
    EmptyNullStringError,
    EmptyTypeFQNError,
    MalformedWireError,
    MismatchedFieldsError,
    UnexpectedComplexValueKindError,
    UnknownTypeError,
)
from .proto import (
    array_element_type,
    bool_value,
    field_name,
    field_type,
    is_null_value,
    list_values,
    number_value,
    proto_type_fqn,
    string_value,
    struct_fields,
    type_code,
    value_kind,
)
from .quote import LiteralQuoteConfig, normalize_literal_quote, to_bytes_literal, to_string_literal, sql_cast_quoted
from .bytes_fmt import decode_base64_wire, readable_string_from_base64_wire
from .float_fmt import (
    float32_to_literal,
    float64_to_literal,
    format_go_g,
    format_spanner_cli_float,
    narrow_float32,
)
from .type_format import format_type_verbose


class Preset(IntEnum):
    SIMPLE = 0
    LITERAL = 1
    SPANNER_CLI = 2


@dataclass(frozen=True)
class FormatConfig:
    preset: Preset = Preset.SIMPLE
    null_string: str = "<null>"
    quote: LiteralQuoteConfig = LiteralQuoteConfig()

    def __post_init__(self) -> None:
        if not self.null_string:
            raise EmptyNullStringError("null_string must not be empty")

    def with_null_string(self, null_string: str) -> FormatConfig:
        return replace(self, null_string=null_string)


def simple_format_config(null_string: str = "<null>") -> FormatConfig:
    return FormatConfig(preset=Preset.SIMPLE, null_string=null_string)


def literal_format_config(
    quote: LiteralQuoteConfig | None = None,
    null_string: str = "NULL",
) -> FormatConfig:
    q = normalize_literal_quote(quote or LiteralQuoteConfig())
    return FormatConfig(preset=Preset.LITERAL, null_string=null_string, quote=q)


def spanner_cli_format_config(null_string: str = "NULL") -> FormatConfig:
    return FormatConfig(preset=Preset.SPANNER_CLI, null_string=null_string)


def _is_complex_type(code: int) -> bool:
    return code in (TypeCode.ARRAY, TypeCode.STRUCT)


def _is_scalar_type(code: int) -> bool:
    return code in (
        TypeCode.BOOL,
        TypeCode.INT64,
        TypeCode.ENUM,
        TypeCode.FLOAT32,
        TypeCode.FLOAT64,
        TypeCode.STRING,
        TypeCode.BYTES,
        TypeCode.PROTO,
        TypeCode.TIMESTAMP,
        TypeCode.DATE,
        TypeCode.NUMERIC,
        TypeCode.JSON,
        TypeCode.INTERVAL,
        TypeCode.UUID,
    )


def _require_string_wire(value: object, code: int) -> None:
    if value_kind(value) != "string":
        raise MalformedWireError(f"{format_type_code_name(code)} value kind {value_kind(value)!r}")


def _require_bool_wire(value: object, code: int) -> None:
    if value_kind(value) != "bool":
        raise MalformedWireError(f"{format_type_code_name(code)} value kind {value_kind(value)!r}")


def _validate_float_wire(value: object, code: int) -> None:
    kind = value_kind(value)
    if kind == "number":
        return
    if kind == "string":
        s = string_value(value)
        if s in ("NaN", "Infinity", "-Infinity"):
            return
        raise MalformedWireError(f"{format_type_code_name(code)} unexpected float string {s!r}")
    raise MalformedWireError(f"{format_type_code_name(code)} value kind {kind!r}")


def format_type_code_name(code: int) -> str:
    from .type_format import format_type_code

    return format_type_code(code)


def _gcv_float64(value: object) -> float:
    import math

    kind = value_kind(value)
    if kind == "number":
        return number_value(value)
    if kind == "string":
        s = string_value(value)
        if s == "NaN":
            return math.nan
        if s == "Infinity":
            return math.inf
        if s == "-Infinity":
            return -math.inf
        raise MalformedWireError(f"FLOAT64 unexpected float string {s!r}")
    raise MalformedWireError(f"FLOAT64 value kind {kind!r}")


def _gcv_float32(value: object) -> float:
    return narrow_float32(_gcv_float64(value))


def _validate_scalar_wire(typ: object, value: object) -> None:
    if typ is None:
        raise MalformedWireError(f"nil type with value kind {value_kind(value)!r}")
    if is_null_value(value):
        raise MalformedWireError(f"{format_type_code_name(type_code(typ))} unexpected null value")
    code = type_code(typ)
    if code == TypeCode.BOOL:
        _require_bool_wire(value, code)
    elif code in (
        TypeCode.INT64,
        TypeCode.ENUM,
        TypeCode.STRING,
        TypeCode.BYTES,
        TypeCode.PROTO,
        TypeCode.TIMESTAMP,
        TypeCode.DATE,
        TypeCode.NUMERIC,
        TypeCode.INTERVAL,
        TypeCode.UUID,
        TypeCode.JSON,
    ):
        _require_string_wire(value, code)
    elif code in (TypeCode.FLOAT32, TypeCode.FLOAT64):
        _validate_float_wire(value, code)
    elif code == TypeCode.TYPE_CODE_UNSPECIFIED:
        raise UnknownTypeError(str(typ))
    elif not _is_scalar_type(code):
        raise UnknownTypeError(str(typ))


def _trim_spanner_cli_numeric_fraction(s: str) -> str:
    if "." not in s:
        return s
    s = s.rstrip("0")
    return s.rstrip(".")


def _numeric_wire_string(value: object) -> str:
    return string_value(value)


def _string_based_literal(type_name: str, payload: str, quote: LiteralQuoteConfig) -> str:
    return f"{type_name} {to_string_literal(payload, quote)}"


def _format_scalar_simple(typ: object, value: object) -> str:
    _validate_scalar_wire(typ, value)
    code = type_code(typ)
    if code == TypeCode.BOOL:
        return "true" if bool_value(value) else "false"
    if code in (
        TypeCode.INT64,
        TypeCode.ENUM,
        TypeCode.STRING,
        TypeCode.TIMESTAMP,
        TypeCode.DATE,
        TypeCode.JSON,
        TypeCode.INTERVAL,
        TypeCode.UUID,
    ):
        return string_value(value)
    if code == TypeCode.FLOAT32:
        return format_go_g(_gcv_float32(value), 32)
    if code == TypeCode.FLOAT64:
        return format_go_g(_gcv_float64(value), 64)
    if code in (TypeCode.BYTES, TypeCode.PROTO):
        return readable_string_from_base64_wire(string_value(value))
    if code == TypeCode.NUMERIC:
        return _numeric_wire_string(value)
    raise UnknownTypeError(str(typ))


def _format_scalar_literal(typ: object, value: object, quote: LiteralQuoteConfig) -> str:
    _validate_scalar_wire(typ, value)
    code = type_code(typ)
    if code == TypeCode.BOOL:
        return "true" if bool_value(value) else "false"
    if code == TypeCode.INT64:
        s = string_value(value)
        try:
            int(s, 10)
        except ValueError as exc:
            raise MalformedWireError(f"invalid INT64 wire {s!r}") from exc
        if int(s) < -(2**63) or int(s) > 2**63 - 1:
            raise MalformedWireError(f"INT64 out of range {s!r}")
        return s
    if code == TypeCode.FLOAT32:
        return float32_to_literal(_gcv_float32(value), quote)
    if code == TypeCode.FLOAT64:
        return float64_to_literal(_gcv_float64(value), quote)
    if code == TypeCode.STRING:
        return to_string_literal(string_value(value), quote)
    if code in (TypeCode.BYTES, TypeCode.PROTO):
        data = decode_base64_wire(string_value(value))
        return to_bytes_literal(data, quote)
    if code == TypeCode.TIMESTAMP:
        return _string_based_literal("TIMESTAMP", string_value(value), quote)
    if code == TypeCode.DATE:
        return _string_based_literal("DATE", string_value(value), quote)
    if code == TypeCode.NUMERIC:
        return _string_based_literal("NUMERIC", _numeric_wire_string(value), quote)
    if code == TypeCode.JSON:
        return _string_based_literal("JSON", string_value(value), quote)
    if code == TypeCode.INTERVAL:
        return sql_cast_quoted(string_value(value), "INTERVAL", quote)
    if code == TypeCode.UUID:
        return sql_cast_quoted(string_value(value), "UUID", quote)
    raise UnknownTypeError(str(typ))


def _format_scalar_spanner_cli(typ: object, value: object) -> str:
    _validate_scalar_wire(typ, value)
    code = type_code(typ)
    if code == TypeCode.BOOL:
        return "true" if bool_value(value) else "false"
    if code in (
        TypeCode.INT64,
        TypeCode.ENUM,
        TypeCode.STRING,
        TypeCode.BYTES,
        TypeCode.PROTO,
        TypeCode.TIMESTAMP,
        TypeCode.DATE,
        TypeCode.INTERVAL,
        TypeCode.UUID,
        TypeCode.JSON,
    ):
        return string_value(value)
    if code == TypeCode.FLOAT32:
        return format_spanner_cli_float(_gcv_float32(value), 32)
    if code == TypeCode.FLOAT64:
        return format_spanner_cli_float(_gcv_float64(value), 64)
    if code == TypeCode.NUMERIC:
        return _trim_spanner_cli_numeric_fraction(_numeric_wire_string(value))
    raise UnknownTypeError(str(typ))


def _format_proto_literal(typ: object, value: object, quote: LiteralQuoteConfig, null_string: str) -> str:
    if type_code(typ) != TypeCode.PROTO:
        raise UnknownTypeError(str(typ))
    if is_null_value(value):
        return null_string
    _require_string_wire(value, TypeCode.PROTO)
    data = decode_base64_wire(string_value(value))
    fqn = proto_type_fqn(typ)
    if not fqn:
        raise EmptyTypeFQNError(f"empty type FQN for PROTO")
    return f"CAST({to_bytes_literal(data, quote)} AS `{fqn}`)"


def _format_enum_literal(typ: object, value: object, null_string: str) -> str:
    if type_code(typ) != TypeCode.ENUM:
        raise UnknownTypeError(str(typ))
    if is_null_value(value):
        return null_string
    _require_string_wire(value, TypeCode.ENUM)
    s = string_value(value)
    try:
        int(s, 10)
    except ValueError as exc:
        raise MalformedWireError(f"failed to parse enum wire payload {s!r}") from exc
    fqn = proto_type_fqn(typ)
    if not fqn:
        raise EmptyTypeFQNError("empty type FQN for ENUM")
    return f"CAST({s} AS `{fqn}`)"


def _format_enum_simple(typ: object, value: object, null_string: str) -> str:
    if is_null_value(value):
        return null_string
    return _format_scalar_simple(typ, value)


def _get_list_value(typ: object, value: object, expected_code: int) -> list[object]:
    if value_kind(value) != "list":
        raise UnexpectedComplexValueKindError(
            f"unexpected complex value kind for {format_type_code_name(expected_code)}: {value_kind(value)!r}"
        )
    return list_values(value)


def format_value(typ: object, value: object, config: FormatConfig, *, toplevel: bool = True) -> str:
    """Format one column value using the given config."""
    if is_null_value(value):
        return config.null_string

    code = type_code(typ)

    if code == TypeCode.ARRAY:
        elems = _get_list_value(typ, value, code)
        elem_type = array_element_type(typ)
        parts = [format_value(elem_type, elem, config, toplevel=False) for elem in elems]
        joined = ", ".join(parts)
        if config.preset == Preset.LITERAL and toplevel and _is_complex_type(type_code(elem_type)):
            return f"{format_type_verbose(typ)}[{joined}]"
        return f"[{joined}]"

    if code == TypeCode.STRUCT:
        field_vals = _get_list_value(typ, value, code)
        fields = struct_fields(typ)
        if len(field_vals) != len(fields):
            raise MismatchedFieldsError(
                f"got {len(field_vals)} values, want {len(fields)}"
            )
        if config.preset == Preset.SIMPLE:
            field_strs = []
            for field, val in zip(fields, field_vals):
                rendered = format_value(field_type(field), val, config, toplevel=False)
                name = field_name(field)
                if name:
                    field_strs.append(f"{rendered} AS {name}")
                else:
                    field_strs.append(rendered)
            return f"({', '.join(field_strs)})"
        field_strs = [
            format_value(field_type(field), val, config, toplevel=False)
            for field, val in zip(fields, field_vals)
        ]
        inner = ", ".join(field_strs)
        if config.preset == Preset.LITERAL:
            prefix = format_type_verbose(typ) if toplevel else ""
            return f"{prefix}({inner})"
        if config.preset == Preset.SPANNER_CLI:
            return f"[{inner}]"
        return f"({inner})"

    if code == TypeCode.PROTO:
        if config.preset == Preset.LITERAL:
            return _format_proto_literal(typ, value, config.quote, config.null_string)
        _require_string_wire(value, code)
        if config.preset == Preset.SPANNER_CLI:
            return string_value(value)
        return readable_string_from_base64_wire(string_value(value))

    if code == TypeCode.ENUM:
        if config.preset == Preset.LITERAL:
            return _format_enum_literal(typ, value, config.null_string)
        return _format_enum_simple(typ, value, config.null_string)

    if code == TypeCode.TYPE_CODE_UNSPECIFIED or not _is_scalar_type(code):
        raise UnknownTypeError(str(typ))

    if config.preset == Preset.SIMPLE:
        return _format_scalar_simple(typ, value)
    if config.preset == Preset.LITERAL:
        return _format_scalar_literal(typ, value, config.quote)
    return _format_scalar_spanner_cli(typ, value)


def format_row(
    types: list[object],
    values: list[object],
    config: FormatConfig,
) -> list[str]:
    """Format a row of column values."""
    if len(types) != len(values):
        raise ValueError(f"len(types)={len(types)} != len(values)={len(values)}")
    return [format_value(t, v, config, toplevel=True) for t, v in zip(types, values)]
