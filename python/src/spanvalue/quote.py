"""GoogleSQL literal quoting and escaping."""

from __future__ import annotations

from dataclasses import dataclass
from enum import IntEnum
import unicodedata


class QuoteStrategy(IntEnum):
    LEGACY = 0
    ALWAYS = 1
    MIN_ESCAPE = 2


class PreferredQuote(IntEnum):
    DOUBLE = 0
    SINGLE = 1


@dataclass(frozen=True)
class LiteralQuoteConfig:
    strategy: QuoteStrategy = QuoteStrategy.LEGACY
    preferred_quote: PreferredQuote = PreferredQuote.DOUBLE


def normalize_literal_quote(cfg: LiteralQuoteConfig) -> LiteralQuoteConfig:
    strategy = cfg.strategy
    if strategy not in (QuoteStrategy.LEGACY, QuoteStrategy.ALWAYS, QuoteStrategy.MIN_ESCAPE):
        strategy = QuoteStrategy.LEGACY
    preferred = cfg.preferred_quote
    if preferred not in (PreferredQuote.DOUBLE, PreferredQuote.SINGLE):
        preferred = PreferredQuote.DOUBLE
    return LiteralQuoteConfig(strategy=strategy, preferred_quote=preferred)


def quote_char_for_payload(payload: bytes | str, cfg: LiteralQuoteConfig) -> str:
    cfg = normalize_literal_quote(cfg)
    if isinstance(payload, str):
        data = payload.encode("utf-8")
    else:
        data = payload

    if cfg.strategy == QuoteStrategy.ALWAYS:
        return "'" if cfg.preferred_quote == PreferredQuote.SINGLE else '"'

    pref = ord("'") if cfg.preferred_quote == PreferredQuote.SINGLE else ord('"')
    other = ord('"') if pref == ord("'") else ord("'")

    if cfg.strategy == QuoteStrategy.MIN_ESCAPE:
        single_count = data.count(b"'")
        double_count = data.count(b'"')
        if single_count < double_count:
            return "'"
        if double_count < single_count:
            return '"'
        return chr(pref)

    has_pref = False
    for b in data:
        if b == other:
            return chr(pref)
        if b == pref:
            has_pref = True
    if has_pref:
        return chr(other)
    return chr(pref)


def escape_rune(r: int, is_string: bool, quote: str) -> str:
    q = ord(quote) if quote else -1
    if r == ord("\\") or (quote and r == q):
        return "\\" + chr(r)
    if is_string and r == ord("\n"):
        return "\\n"
    if is_string and r == ord("\r"):
        return "\\r"
    if is_string and r == ord("\t"):
        return "\\t"
    if is_string and _is_printable(r):
        return chr(r)
    if 0x20 <= r <= 0x7E:
        return chr(r)
    if r < 0x100:
        return f"\\x{r:02x}"
    if r > 0xFFFF:
        return f"\\U{r:08x}"
    return f"\\u{r:04x}"


def _is_printable(r: int) -> bool:
    if r == 0x20:
        return True
    cat = unicodedata.category(chr(r))
    return cat[0] in ("L", "M", "N", "P", "S")


def to_string_literal(s: str, cfg: LiteralQuoteConfig) -> str:
    quote = quote_char_for_payload(s, cfg)
    parts = [quote]
    for ch in s:
        parts.append(escape_rune(ord(ch), True, quote))
    parts.append(quote)
    return "".join(parts)


def to_bytes_literal(data: bytes, cfg: LiteralQuoteConfig) -> str:
    quote = quote_char_for_payload(data, cfg)
    parts = ["b", quote]
    for b in data:
        parts.append(escape_rune(b, False, quote))
    parts.append(quote)
    return "".join(parts)


def sql_cast_quoted(payload: str, cast_type: str, cfg: LiteralQuoteConfig) -> str:
    lit = to_string_literal(payload, cfg)
    return f"CAST({lit} AS {cast_type})"
