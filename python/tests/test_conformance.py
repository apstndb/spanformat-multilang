"""Conformance tests against shared testdata."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from spanvalue import (
    FORMAT_OPTION_MORE_VERBOSE,
    FORMAT_OPTION_NORMAL,
    FORMAT_OPTION_SIMPLE,
    FORMAT_OPTION_SIMPLEST,
    FORMAT_OPTION_VERBOSE,
    LiteralQuoteConfig,
    PreferredQuote,
    QuoteStrategy,
    format_type,
    format_type_verbose_annotation_omit,
    format_type_verbose_annotation_primary,
    format_value,
    literal_format_config,
    simple_format_config,
    spanner_cli_format_config,
)

CONFORMANCE_PATH = Path(__file__).resolve().parents[2] / "testdata" / "conformance.json"

TYPE_PRESETS = {
    "simplest": FORMAT_OPTION_SIMPLEST,
    "simple": FORMAT_OPTION_SIMPLE,
    "normal": FORMAT_OPTION_NORMAL,
    "verbose": FORMAT_OPTION_VERBOSE,
    "more_verbose": FORMAT_OPTION_MORE_VERBOSE,
}

QUOTE_POLICIES = {
    "legacy_double": LiteralQuoteConfig(QuoteStrategy.LEGACY, PreferredQuote.DOUBLE),
    "legacy_single": LiteralQuoteConfig(QuoteStrategy.LEGACY, PreferredQuote.SINGLE),
    "always_double": LiteralQuoteConfig(QuoteStrategy.ALWAYS, PreferredQuote.DOUBLE),
    "always_single": LiteralQuoteConfig(QuoteStrategy.ALWAYS, PreferredQuote.SINGLE),
    "min_escape_double": LiteralQuoteConfig(QuoteStrategy.MIN_ESCAPE, PreferredQuote.DOUBLE),
    "min_escape_single": LiteralQuoteConfig(QuoteStrategy.MIN_ESCAPE, PreferredQuote.SINGLE),
}


def _load_conformance() -> dict:
    with CONFORMANCE_PATH.open(encoding="utf-8") as f:
        return json.load(f)


def _format_type_preset(name: str, typ: dict) -> str:
    if name == "verbose_annotation_omit":
        return format_type_verbose_annotation_omit(typ)
    if name == "verbose_annotation_primary":
        return format_type_verbose_annotation_primary(typ)
    return format_type(typ, TYPE_PRESETS[name])


@pytest.fixture(scope="module")
def conformance() -> dict:
    return _load_conformance()


@pytest.mark.parametrize("preset", list(TYPE_PRESETS) + ["verbose_annotation_omit", "verbose_annotation_primary"])
def test_type_cases(conformance: dict, preset: str) -> None:
    for case in conformance["type_cases"]:
        got = _format_type_preset(preset, case["type"])
        want = case["expected"][preset]
        assert got == want, f"type case {case['name']!r} preset {preset!r}: got {got!r} want {want!r}"


@pytest.mark.parametrize("preset", ["simple", "literal", "spanner_cli"])
def test_value_cases(conformance: dict, preset: str) -> None:
    if preset == "simple":
        config = simple_format_config()
    elif preset == "literal":
        config = literal_format_config()
    else:
        config = spanner_cli_format_config()

    for case in conformance["value_cases"]:
        got = format_value(case["type"], case["value"], config)
        want = case["expected"][preset]
        assert got == want, f"value case {case['name']!r} preset {preset!r}: got {got!r} want {want!r}"


@pytest.mark.parametrize("policy_name", list(QUOTE_POLICIES))
def test_value_literal_quotes(conformance: dict, policy_name: str) -> None:
    config = literal_format_config(quote=QUOTE_POLICIES[policy_name])
    for case in conformance["value_cases"]:
        got = format_value(case["type"], case["value"], config)
        want = case["expected"]["literal_quotes"][policy_name]
        assert got == want, f"value case {case['name']!r} quote {policy_name!r}: got {got!r} want {want!r}"
