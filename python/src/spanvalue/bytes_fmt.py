"""BYTES/PROTO readable formatting."""

from __future__ import annotations

import base64

from .quote import escape_rune


def decode_base64_wire(wire: str) -> bytes:
    if wire == "":
        return b""
    return base64.standard_b64decode(wire)


def _is_readable_ascii(data: bytes) -> bool:
    for c in data:
        if c == ord("\\") or c < 0x20 or c > 0x7E:
            return False
    return True


def readable_bytes_string(data: bytes) -> str:
    if not data:
        return ""
    if _is_readable_ascii(data):
        return data.decode("ascii")
    parts: list[str] = []
    for b in data:
        parts.append(escape_rune(b, False, ""))
    return "".join(parts)


def readable_string_from_base64_wire(wire: str) -> str:
    return readable_bytes_string(decode_base64_wire(wire))
