# spanformat-multilang

Multi-language ports of Cloud Spanner **type** formatting ([spantype](https://github.com/apstndb/spantype)) and **value** formatting ([spanvalue](https://github.com/apstndb/spanvalue)); the repository name reflects both surfaces.

Type and value formatting libraries for Cloud Spanner, ported to every
language with an official Spanner client library.

This repository ports two Go libraries to other languages:

- [`github.com/apstndb/spantype`](https://github.com/apstndb/spantype) —
  format `google.spanner.v1.Type` values at multiple verbosity levels
  (`STRUCT`, `STRUCT<ARRAY<STRUCT<INT64>>, Book>`,
  `STRUCT<arr ARRAY<STRUCT<n INT64>>, proto examples.Book>`, ...).
- [`github.com/apstndb/spanvalue`](https://github.com/apstndb/spanvalue) —
  format Spanner column values (`Type` + `google.protobuf.Value` pairs, the
  wire form every client library exposes) as human-readable text, re-parseable
  GoogleSQL literals, or byte-for-byte
  [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
  compatible output.

## Input model

Formatting is defined on the Spanner **wire** representation (see
[`spec/FORMAT.md`](spec/FORMAT.md) §1):

- **Type** — `google.spanner.v1.Type` (`code`, optional `array_element_type`,
  `struct_type.fields[]`, `proto_type_fqn`, `type_annotation`).
- **Value** — `google.protobuf.Value` (Spanner encodes each column by type:
  `string_value` for INT64/STRING/ENUM, `list_value` for ARRAY/STRUCT, etc.).

This pair is the core API every port targets. In practice, callers pass either:

1. **Protobuf messages** from the official client (where the port supports
   duck-typing or direct proto types), or
2. **Protojson-shaped data** — plain dicts/objects/JSON with snake_case or
   camelCase keys. Conformance tests use this form; it also accepts plain JSON
   shorthand for values (e.g. bare `"42"` or `["1", "foo"]` for STRUCT, not only
   `{ "stringValue": "42" }` wrappers).

### High-level client row values

Official Spanner client libraries expose ergonomic row types (`Struct`,
typed getters, native scalars, etc.) that are **not** wire-shaped. Every port
now ships a **gcvctor-style encoder** (see [`spec/ENCODER.md`](spec/ENCODER.md))
to build wire `google.protobuf.Value` from native values, plus
`format_result_row` to format query-result columns in one call:

```
wire_value = encode_value(wire_type, native_value)
formatted  = format_result_row(types, native_values, config)
```

Go upstream handles the same path via `spanvalue/gcvctor` and
`FormatRowColumns`.

### Per-language input support

| Language | Type inputs | Value inputs | Encoder / adapter | Notes |
|---|---|---|---|---|
| Go | (upstream) | (upstream) | `gcvctor`, `FormatRowColumns` | Reference implementation |
| Python | protojson dict, duck-typed protos | protojson, duck-typed protos | `encode_value`, `adapt_client_type`, `format_result_row` | Optional `google-cloud-spanner`; see [`python/README.md`](python/README.md) |
| Java | protojson `Map`, `com.google.spanner.v1.Type`, `com.google.cloud.spanner.Type` (via adapter) | protojson, `com.google.protobuf.Value`, native via `Gcvctor` | `Gcvctor.encodeValue`, `ClientTypeAdapter`, `formatResultRow` | STRUCT adapter maps `getStructFields()` → `struct_type.fields`; see [`java/README.md`](java/README.md) |
| Node.js | plain objects, duck-typed protos, client types (adapter) | plain JS + arrays, duck-typed protos, native via encoder | `encodeValue`, `adaptClientType`, `formatResultRow` | See [`nodejs/README.md`](nodejs/README.md) |
| Ruby | `Hash`, duck-typed protos, client types (adapter) | arrays, `Hash`, duck-typed protos, native via encoder | `encode_value`, `ClientTypeAdapter.adapt`, `format_result_row` | See [`ruby/README.md`](ruby/README.md) |
| PHP | arrays, duck-typed protos, `Google\Cloud\Spanner\V1\Type` | arrays, duck-typed protos, `Google\Protobuf\Value`, native via encoder | `encode_value`, `format_result_row` | Protobuf getter reflection; see [`php/README.md`](php/README.md) |
| C# | protojson, `Google.Protobuf.IMessage`, `SpannerDbType` (adapter) | protojson, protobuf `Value`, native via encoder | `ValueEncoder.EncodeValue`, `AdaptClientType`, `FormatResultRow` | See [`csharp/README.md`](csharp/README.md) |
| Rust | native `spanvalue::Type`, `TypeLike` trait | native `spanvalue::Value`, `ValueLike` trait | `encode_value`, `format_result_row` | Trait-based duck-typing; see [`rust/README.md`](rust/README.md) |
| C++ | `nlohmann::json`, optional protobuf template | `nlohmann::json`, native via encoder | `encode_value`, `format_result_row` | No protobuf dep by default; see [`cpp/README.md`](cpp/README.md) |

## Languages

Official Spanner client libraries exist for C++, C#, Go, Java, Node.js, PHP,
Python, and Ruby (see the
[client libraries page](https://cloud.google.com/spanner/docs/reference/libraries)),
plus the preview Rust client in
[googleapis/google-cloud-rust](https://github.com/googleapis/google-cloud-rust).

| Language | Directory | Package |
|---|---|---|
| Go | (upstream) | [`apstndb/spantype`](https://github.com/apstndb/spantype), [`apstndb/spanvalue`](https://github.com/apstndb/spanvalue) |
| C++ | [`cpp/`](cpp/) | `spanvalue` (header-first, CMake) |
| C# | [`csharp/`](csharp/) | `Apstndb.SpanValue` |
| Java | [`java/`](java/) | `com.github.apstndb:spanvalue` |
| Node.js | [`nodejs/`](nodejs/) | `@apstndb/spanvalue` |
| PHP | [`php/`](php/) | `apstndb/spanvalue` |
| Python | [`python/`](python/) | `spanvalue` |
| Ruby | [`ruby/`](ruby/) | `spanvalue` |
| Rust | [`rust/`](rust/) | `spanvalue` |

Go is served by the upstream libraries themselves; they are also the reference
implementation that generates this repository's conformance data.

## Build and test

Each port ships a conformance test against the shared
[`testdata/conformance.json`](testdata/conformance.json). From the repository
root:

| Language | Requirements | Test command |
|---|---|---|
| Python | Python 3.10+ | `cd python && pip install -e ".[dev]" && pytest` |
| Java | JDK 17+, Maven | `cd java && mvn test` |
| C# | .NET 8 SDK | `cd csharp && dotnet test` |
| Rust | Rust stable | `cd rust && cargo test` |
| PHP | PHP 8.1+ with `intl` | `cd php && composer install && vendor/bin/phpunit` |
| Ruby | Ruby 3.2+ | `cd ruby && gem install minitest && ruby -Itest test/test_conformance.rb test/test_encoder.rb` |
| Node.js | Node.js 18+ | `cd nodejs && npm test` |
| C++ | CMake 3.16+, C++17 | `cd cpp && cmake -B build && cmake --build build && ctest --test-dir build` |

Verify the conformance data generator (Go reference implementation):

```console
$ cd tools/gen-testdata && go run .
```

CI runs all of the above on every push and pull request to `main`.

## Integration examples

Runnable demos that query the [Spanner emulator](https://cloud.google.com/spanner/docs/emulator) with each official client library and format results via `encode_value` / `adapt_client_type` / `format_result_row` are in [`examples/`](examples/). See [`examples/README.md`](examples/README.md) for setup and one-liner run commands.

For a per-language walkthrough of client APIs, metadata timing, and pitfalls, see **[`docs/CLIENT_INTEGRATION.md`](docs/CLIENT_INTEGRATION.md)** (Japanese).

## How consistency is enforced

- [`spec/FORMAT.md`](spec/FORMAT.md) is the normative specification for both
  type formatting and value formatting, including the GoogleSQL escaping
  rules, quote selection policies, and Go-`strconv`-compatible float
  rendering.
- [`spec/PROTOFMT.md`](spec/PROTOFMT.md) specifies the optional
  descriptor-aware PROTO/ENUM value display layer (port of Go
  `spanvalue/protofmt`); it does not alter the default conformance surface.
- [`testdata/conformance.json`](testdata/conformance.json) contains shared
  conformance cases (types and values in protojson form with expected output
  for every preset and quote policy). It is generated by
  [`tools/gen-testdata`](tools/gen-testdata), a Go program that calls the
  original `spantype`/`spanvalue` libraries, so the expected strings come from
  the battle-tested reference implementation rather than from a re-invention.
- Every language port ships a conformance test that loads the shared data and
  asserts byte-for-byte equality.

To regenerate the conformance data after changing the case list:

```console
$ cd tools/gen-testdata && go run .
```

## Formatting surface (all ports)

Type formatting presets (spec section 2): `SIMPLEST`, `SIMPLE`, `NORMAL`,
`VERBOSE`, `MORE_VERBOSE`, each configurable per axis (struct / proto / enum /
array / unknown-code rendering and PostgreSQL `TypeAnnotation` handling).

Value formatting presets (spec section 3):

| Preset | Null | Example (`STRUCT<n INT64, s STRING>`) |
|---|---|---|
| `SIMPLE` | `<null>` | `(1 AS n, foo AS s)` |
| `LITERAL` | `NULL` | `STRUCT<n INT64, s STRING>(1, "foo")` |
| `SPANNER_CLI` | `NULL` | `[1, foo]` |

`LITERAL` supports quote-selection policies (legacy adaptive, always,
minimal-escape; double- or single-quote preferred) matching upstream
`spanvalue`.

## Repository layout

```
spec/FORMAT.md            normative formatting specification
spec/PROTOFMT.md          optional protofmt (descriptor-aware PROTO/ENUM values)
spec/ENCODER.md           wire encoder (gcvctor) specification
testdata/conformance.json shared conformance cases (generated)
tools/gen-testdata/       Go generator (reference implementation)
<language>/               one library per language, self-contained
```

## License

MIT (matching the upstream Go libraries).
