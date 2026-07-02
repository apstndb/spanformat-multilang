"""Encoder unit tests derived from conformance value_cases."""

from __future__ import annotations

import base64
import json
import math
from pathlib import Path
from typing import Any

import pytest

from spanvalue import (
    encode_value,
    format_result_row,
    format_value,
    simple_format_config,
)
from spanvalue.encoder import adapt_client_type
from spanvalue.proto import (
    array_element_type,
    bool_value,
    field_type,
    is_null_value,
    list_values,
    number_value,
    string_value,
    struct_fields,
    type_code,
    value_kind,
)
from spanvalue.codes import TypeCode

CONFORMANCE_PATH = Path(__file__).resolve().parents[2] / "testdata" / "conformance.json"

ENCODER_CASE_NAMES = [
    "bool_true",
    "bool_false",
    "bool_null",
    "int64_positive",
    "int64_null",
    "float64_pi",
    "float64_nan",
    "float64_null",
    "string_plain",
    "bytes_ascii",
    "bytes_null",
    "array_int64",
    "array_int64_empty",
    "array_int64_with_null",
    "struct_named",
    "struct_with_null_field",
]


def _load_cases() -> dict[str, dict[str, Any]]:
    with CONFORMANCE_PATH.open(encoding="utf-8") as f:
        data = json.load(f)
    return {case["name"]: case for case in data["value_cases"]}


CASES = _load_cases()

try:
    import google.cloud.spanner  # noqa: F401

    HAS_CLOUD_SPANNER = True
except ImportError:
    HAS_CLOUD_SPANNER = False


def _wire_to_native(typ: dict[str, Any], wire: Any) -> Any:
    if wire is None or (isinstance(wire, dict) and is_null_value(wire)):
        return None

    code = type_code(typ)
    kind = value_kind(wire)

    if code == TypeCode.BOOL:
        return bool_value(wire) if kind == "bool" else bool(wire)

    if code in (TypeCode.INT64, TypeCode.ENUM):
        return int(string_value(wire) if kind == "string" else wire)

    if code in (TypeCode.FLOAT32, TypeCode.FLOAT64):
        if kind == "number":
            return number_value(wire)
        s = string_value(wire)
        if s == "NaN":
            return math.nan
        if s == "Infinity":
            return math.inf
        if s == "-Infinity":
            return -math.inf
        raise AssertionError(f"unexpected float wire {wire!r}")

    if code in (
        TypeCode.STRING,
        TypeCode.TIMESTAMP,
        TypeCode.DATE,
        TypeCode.NUMERIC,
        TypeCode.JSON,
        TypeCode.INTERVAL,
        TypeCode.UUID,
        TypeCode.PROTO,
        TypeCode.ENUM,
    ):
        return string_value(wire) if kind == "string" else str(wire)

    if code == TypeCode.BYTES:
        wire_str = string_value(wire) if kind == "string" else str(wire)
        return base64.standard_b64decode(wire_str) if wire_str else b""

    if code == TypeCode.ARRAY:
        elem_type = array_element_type(typ)
        items = list_values(wire) if kind == "list" else wire
        return [_wire_to_native(elem_type, item) for item in items]

    if code == TypeCode.STRUCT:
        fields = struct_fields(typ)
        items = list_values(wire) if kind == "list" else wire
        return [
            _wire_to_native(field_type(field), item)
            for field, item in zip(fields, items)
        ]

    raise AssertionError(f"unsupported type code {code} for wire_to_native")


def _normalize_wire(value: Any) -> Any:
    if value is None:
        return None
    kind = value_kind(value)
    if kind in ("null", "missing"):
        return None
    if kind == "bool":
        return {"bool_value": bool_value(value)}
    if kind == "number":
        return {"number_value": number_value(value)}
    if kind == "string":
        return {"string_value": string_value(value)}
    if kind == "list":
        return {"list_value": {"values": [_normalize_wire(v) for v in list_values(value)]}}
    if isinstance(value, bool):
        return {"bool_value": value}
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return {"number_value": float(value)}
    if isinstance(value, str):
        return {"string_value": value}
    if isinstance(value, list):
        return {"list_value": {"values": [_normalize_wire(v) for v in value]}}
    raise AssertionError(f"cannot normalize wire value {value!r}")


@pytest.mark.parametrize("case_name", ENCODER_CASE_NAMES)
def test_encode_value_round_trip(case_name: str) -> None:
    case = CASES[case_name]
    typ = case["type"]
    native = _wire_to_native(typ, case["value"])
    encoded = encode_value(typ, native)
    assert _normalize_wire(encoded) == _normalize_wire(case["value"])
    got = format_value(typ, encoded, simple_format_config())
    assert got == case["expected"]["simple"]


def test_format_result_row() -> None:
    types = [
        {"code": "INT64"},
        {"code": "STRING"},
        {
            "code": "STRUCT",
            "structType": {
                "fields": [
                    {"name": "n", "type": {"code": "INT64"}},
                    {"name": "s", "type": {"code": "STRING"}},
                ]
            },
        },
    ]
    values = [42, "east", [7, None]]
    config = simple_format_config()
    got = format_result_row(types, values, config)
    assert got == ["42", "east", "(7 AS n, <null> AS s)"]


def test_adapt_client_type_dict_passthrough() -> None:
    typ = {"code": "INT64"}
    assert adapt_client_type(typ) is typ


@pytest.mark.skipif(not HAS_CLOUD_SPANNER, reason="google-cloud-spanner not installed")
def test_adapt_cloud_spanner_struct() -> None:
    from google.cloud.spanner import Type

    client_type = Type.struct(
        [
            Type.StructField.of("n", Type.int64()),
            Type.StructField.of("s", Type.string()),
        ]
    )
    wire = adapt_client_type(client_type)
    assert wire["code"] == "STRUCT"
    fields = wire["struct_type"]["fields"]
    assert len(fields) == 2
    assert fields[0]["name"] == "n"
    assert fields[0]["type"]["code"] == "INT64"
    assert fields[1]["name"] == "s"
    assert fields[1]["type"]["code"] == "STRING"
