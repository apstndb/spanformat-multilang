# Wire encoder specification (gcvctor ports)

Companion to [`FORMAT.md`](FORMAT.md). Formatting operates on wire
`(google.spanner.v1.Type, google.protobuf.Value)` pairs. This document
defines how to **build** wire values from native/client types so callers can
format query results, logs, and errors without hand-assembling protojson.

Go reference: `.refs/spanvalue/gcvctor/` and `FormatRowColumns` in
`.refs/spanvalue/row.go`.

## APIs (per language)

| API | Input | Output |
|---|---|---|
| `encode_value(type, native_value)` | wire `Type` + native scalar/collection | wire `Value` |
| `adapt_client_type(client_type)` | client wrapper type | wire `Type` |
| `format_result_row(types, values, config)` | parallel type + native value lists | `list<string>` formatted cells |

`format_result_row` = `encode_value` per column, then existing `format_row`.

Package naming: follow each port (`gcvctor`, `encode`, `encoder` submodule).
Export from the language's main module alongside `format_value`.

## Wire encoding rules

See FORMAT.md §1. Summary for encoders:

| TypeCode | Native → wire |
|---|---|
| any, SQL NULL | `null_value` |
| BOOL | `bool_value` |
| INT64, ENUM | `string_value` (decimal integer) |
| FLOAT32/64 | `number_value` if finite; else `string_value` `"NaN"` / `"Infinity"` / `"-Infinity"` |
| STRING | `string_value` |
| BYTES, PROTO | `string_value` (RFC 4648 base64 with padding) |
| TIMESTAMP | `string_value` (RFC 3339 UTC, `Z`) |
| DATE | `string_value` (`YYYY-MM-DD`) |
| NUMERIC | `string_value` (canonical decimal; PG_NUMERIC may be `"NaN"`) |
| JSON / PG_JSONB | `string_value` (valid JSON text) |
| INTERVAL | `string_value` (ISO 8601 duration) |
| UUID | `string_value` |
| ARRAY | `list_value` of element encodings |
| STRUCT | `list_value` of field encodings in field order |

Missing/nil native value → typed SQL NULL (`null_value`) when the type is known.

## Composite construction

- **ARRAY**: element types must match; empty array → `ARRAY<elemType>` with
  empty `list_value` (not SQL NULL).
- **STRUCT**: field count must match type; each field encoded with its field
  type. Unnamed fields allowed (empty name).
- No implicit type coercion between element/field types.

## Client type adapters

Convert client wrapper types to wire `Type` before encode/format:

| Language | Client type | Notes |
|---|---|---|
| Java | `com.google.cloud.spanner.Type` | STRUCT: `getStructFields()` → `struct_type.fields` |
| Python | `google.cloud.spanner` types | Optional; only if dep already present |
| C# | `Google.Cloud.Spanner.V1.Type` or `SpannerDbType` | Map to wire proto |
| Node.js | `@google-cloud/spanner` types | Structural duck-typing |
| Ruby | `Google::Cloud::Spanner` types | Structural duck-typing |

Adapters are best-effort; wire protojson dicts remain the universal fallback.

## Testing

1. Do not break `testdata/conformance.json` tests.
2. Add encoder unit tests derived from conformance `value_cases`: decode the
   wire `(type, value)` pair, treat `value` as the expected wire output of
   `encode_value(type, native_decoded)`, round-trip through `format_value`.
3. For `format_result_row`, test a small row (2–3 columns) including NULL and
   STRUCT.

## Out of scope (P5)

Descriptor-aware PROTO/ENUM **value display** (`protofmt`) is lower priority for
encoder work; encoders only need FQN + bytes/int wire forms. Design for the
optional display layer is in [`spec/PROTOFMT.md`](PROTOFMT.md).
