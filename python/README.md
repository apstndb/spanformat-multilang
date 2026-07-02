# spanvalue (Python)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero runtime dependencies. Python 3.10+.

## Install

```bash
cd python
uv venv
uv pip install -e .
```

## Quick start

```python
from spanvalue import (
    format_type_simple,
    format_value,
    simple_format_config,
    literal_format_config,
    spanner_cli_format_config,
)

# Type formatting presets
typ = {
    "code": "STRUCT",
    "structType": {
        "fields": [
            {"name": "n", "type": {"code": "INT64"}},
            {"name": "s", "type": {"code": "STRING"}},
        ]
    },
}
print(format_type_simple(typ))  # STRUCT

# Value formatting presets
row_type = typ
row_value = ["1", "hello"]

print(format_value(row_type, row_value, simple_format_config()))
# (1 AS n, hello AS s)

print(format_value(row_type, row_value, literal_format_config()))
# STRUCT<n INT64, s STRING>(1, "hello")

print(format_value(row_type, row_value, spanner_cli_format_config()))
# [1, hello]
```

## Integration with `google-cloud-spanner`

The library accepts protojson dicts **and** duck-typed protobuf objects from the
official client. No hard dependency on `google-cloud-spanner` is required.

**Note:** high-level client row types (`Struct`, typed column getters) can be
encoded with `encode_value` / `format_result_row` when you have the column
`Type` metadata and native Python values (scalars, lists, dicts for STRUCT).

```python
from spanvalue import encode_value, format_result_row, simple_format_config

col_types = [
    {"code": "INT64"},
    {"code": "STRING"},
]
native_values = [42, "east"]
cells = format_result_row(col_types, native_values, simple_format_config())
print(cells)  # ['42', 'east']
```

Optional: adapt `google.cloud.spanner` client types to wire `Type` dicts:

```python
from google.cloud.spanner import Type
from spanvalue import adapt_client_type

wire_type = adapt_client_type(Type.struct([
    Type.StructField.of("n", Type.int64()),
]))
```

When formatting pre-encoded wire values, pair each column's `Type` from result metadata
with the corresponding `Value` from the row:

```python
# Pseudocode for a streaming read result
config = simple_format_config()
for row in snapshot.execute_sql("SELECT id, name FROM Users"):
    values = [...]  # list of google.protobuf.Value per column
    cells = format_row(column_types, values, config)
```

## API surface

- **Type formatting**: `format_type`, `FormatOption`, presets
  `format_type_simplest|simple|normal|verbose|more_verbose`, axes
  `StructMode`, `ProtoEnumMode`, `ArrayMode`, `UnknownMode`, `TypeAnnotationMode`.
- **Value formatting**: `FormatConfig` via `simple_format_config`,
  `literal_format_config` (with `LiteralQuoteConfig`), `spanner_cli_format_config`;
  `format_value`, `format_row`.
- **Wire encoding**: `encode_value`, `format_result_row`, optional
  `adapt_client_type` (requires `google-cloud-spanner` when adapting client types).
- **Errors**: `MalformedWireError`, `UnknownTypeError`, `MismatchedFieldsError`,
  `EmptyTypeFQNError`, `EmptyNullStringError`.

## Tests

```bash
cd python
uv venv
uv pip install pytest
uv run pytest -v
```

Conformance cases load `../testdata/conformance.json`.

## Integration example

See [`../examples/python/`](../examples/python/) for a runnable Spanner emulator demo (`query_format.py`) that executes SQL and formats cells with `encode_value`, `adapt_client_type`, and `format_result_row`. Setup: [`../examples/README.md`](../examples/README.md).

## License

MIT
