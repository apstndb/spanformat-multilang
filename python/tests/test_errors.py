"""Unit tests for error paths."""

from __future__ import annotations

import math

import pytest

from spanvalue import (
    EmptyNullStringError,
    EmptyTypeFQNError,
    MalformedWireError,
    MismatchedFieldsError,
    UnexpectedComplexValueKindError,
    UnknownTypeError,
    format_row,
    format_value,
    literal_format_config,
    simple_format_config,
)
from spanvalue.type_format import FormatOption, UnknownMode, format_type


def test_empty_null_string_rejected() -> None:
    with pytest.raises(EmptyNullStringError):
        simple_format_config(null_string="")


def test_unknown_type_code_value() -> None:
    config = simple_format_config()
    with pytest.raises(UnknownTypeError):
        format_value({"code": 999}, True, config)


def test_unknown_type_code_format_panic() -> None:
    opt = FormatOption(unknown=UnknownMode.PANIC)
    with pytest.raises(UnknownTypeError):
        format_type({"code": 999}, opt)


def test_mismatched_struct_fields() -> None:
    config = simple_format_config()
    typ = {
        "code": "STRUCT",
        "structType": {
            "fields": [
                {"name": "n", "type": {"code": "INT64"}},
                {"name": "s", "type": {"code": "STRING"}},
            ]
        },
    }
    with pytest.raises(MismatchedFieldsError):
        format_value(typ, ["1"], config)


def test_malformed_bool_wire() -> None:
    config = simple_format_config()
    with pytest.raises(MalformedWireError):
        format_value({"code": "BOOL"}, "true", config)


def test_malformed_int64_wire() -> None:
    config = literal_format_config()
    with pytest.raises(MalformedWireError):
        format_value({"code": "INT64"}, "not-a-number", config)


def test_malformed_float_wire() -> None:
    config = simple_format_config()
    with pytest.raises(MalformedWireError):
        format_value({"code": "FLOAT64"}, "3.14", config)


def test_empty_proto_fqn_literal() -> None:
    config = literal_format_config()
    with pytest.raises(EmptyTypeFQNError):
        format_value({"code": "PROTO"}, "YWJj", config)


def test_empty_enum_fqn_literal() -> None:
    config = literal_format_config()
    with pytest.raises(EmptyTypeFQNError):
        format_value({"code": "ENUM"}, "1", config)


def test_format_row_length_mismatch() -> None:
    config = simple_format_config()
    with pytest.raises(ValueError):
        format_row([{"code": "INT64"}], ["1", "2"], config)


def test_null_renders_at_any_depth() -> None:
    config = simple_format_config()
    typ = {
        "code": "ARRAY",
        "arrayElementType": {"code": "INT64"},
    }
    assert format_value(typ, [None], config) == "[<null>]"


def test_type_unspecified_value_error() -> None:
    config = simple_format_config()
    with pytest.raises(UnknownTypeError):
        format_value({}, True, config)


def test_array_non_list_wire() -> None:
    config = simple_format_config()
    typ = {"code": "ARRAY", "arrayElementType": {"code": "INT64"}}
    with pytest.raises(UnexpectedComplexValueKindError):
        format_value(typ, "1", config)


def test_nan_float_wire_string() -> None:
    config = simple_format_config()
    got = format_value({"code": "FLOAT64"}, "NaN", config)
    assert got == "NaN"


def test_inf_float_wire_string() -> None:
    config = simple_format_config()
    got = format_value({"code": "FLOAT64"}, "Infinity", config)
    assert got == "+Inf"


def test_negative_inf_float_wire_string() -> None:
    config = simple_format_config()
    got = format_value({"code": "FLOAT64"}, "-Infinity", config)
    assert got == "-Inf"


def test_number_wire_finite_float() -> None:
    config = simple_format_config()
    got = format_value({"code": "FLOAT64"}, math.pi, config)
    assert got == "3.141592653589793"
