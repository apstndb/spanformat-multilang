"""Go strconv.FormatFloat(v, 'g', -1, bits) compatible formatting."""

from __future__ import annotations

import math
import re

from .quote import LiteralQuoteConfig, sql_cast_quoted

_E_RE = re.compile(r"^(\d)(?:\.(\d+))?e([+-])(\d+)$")


def narrow_float32(v: float) -> float:
    import ctypes

    return ctypes.c_float(v).value


def _pack_float32(v: float) -> float:
    import ctypes

    return ctypes.c_float(v).value


def _round_trips(s: str, original: float, bits: int) -> bool:
    try:
        parsed = float(s)
    except ValueError:
        return False
    if math.isnan(original):
        return math.isnan(parsed)
    if math.isinf(original):
        return math.isinf(parsed) and (parsed > 0) == (original > 0)
    if bits == 32:
        try:
            return _pack_float32(parsed) == _pack_float32(original)
        except OverflowError:
            return False
    return parsed == original


def _fmt_exponent(exp: int) -> str:
    if exp >= 0:
        return f"e+{exp:02d}"
    ae = abs(exp)
    if ae < 10:
        return f"e-{ae:02d}"
    return f"e{exp}"


def _pe_to_go_g(es: str) -> str:
    m = _E_RE.match(es)
    if not m:
        raise ValueError(f"unexpected e-format: {es!r}")
    d1, rest, esign, eexp = m.groups()
    rest = rest or ""
    exp = int(esign + eexp)
    sig = d1 + rest.rstrip("0")
    if not sig:
        sig = "0"
    ndigits = len(sig)

    if -4 <= exp < 6:
        dec_pos = 1 + exp
        if dec_pos <= 0:
            s = "0." + ("0" * -dec_pos) + sig
        elif dec_pos >= ndigits:
            s = sig + ("0" * (dec_pos - ndigits))
        else:
            s = sig[:dec_pos] + "." + sig[dec_pos:]
        if "." in s:
            s = s.rstrip("0").rstrip(".")
        return s

    if ndigits == 1:
        body = sig
    else:
        body = sig[0] + "." + sig[1:]
    return body + _fmt_exponent(exp)


def format_go_g(v: float, bits: int = 64) -> str:
    """Match Go strconv.FormatFloat(v, 'g', -1, bits)."""
    if bits == 32:
        v = narrow_float32(v)

    if math.isnan(v):
        return "NaN"
    if math.isinf(v):
        return "-Inf" if v < 0 else "+Inf"
    if v == 0.0 and math.copysign(1.0, v) < 0:
        return "-0"

    negative = v < 0
    av = -v if negative else v
    max_p = 16 if bits == 64 else 8

    best: str | None = None
    target = v if bits == 64 else narrow_float32(v)
    for p in range(max_p + 1):
        es = format(av, f".{p}e")
        g = _pe_to_go_g(es)
        candidate = ("-" if negative else "") + g
        if _round_trips(candidate, target, bits):
            if best is None or len(candidate) < len(best):
                best = candidate

    if best is None:
        best = ("-" if negative else "") + repr(av)
    return best


def format_spanner_cli_float(v: float, bits: int = 64) -> str:
    """Match spanner-cli float rendering."""
    if bits == 32:
        v = narrow_float32(v)
    if math.isnan(v):
        return "NaN"
    if math.isinf(v):
        return "-Inf" if v < 0 else "+Inf"
    if v == math.trunc(v):
        return format(v, ".0f")
    return format(v, ".6f")


def float64_to_literal(v: float, quote_cfg: LiteralQuoteConfig) -> str:
    if math.isnan(v):
        return sql_cast_quoted("nan", "FLOAT64", quote_cfg)
    if math.isinf(v):
        return sql_cast_quoted("-inf" if v < 0 else "inf", "FLOAT64", quote_cfg)
    s = format_go_g(v, 64)
    if not any(ch in s for ch in ".eE"):
        s += ".0"
    return s


def float32_to_literal(v: float, quote_cfg: LiteralQuoteConfig) -> str:
    fv = narrow_float32(v)
    if math.isnan(fv):
        return sql_cast_quoted("nan", "FLOAT32", quote_cfg)
    if math.isinf(fv):
        return sql_cast_quoted("-inf" if fv < 0 else "inf", "FLOAT32", quote_cfg)
    return f"CAST({format_go_g(fv, 32)} AS FLOAT32)"
