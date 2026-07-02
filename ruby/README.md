# spanvalue (Ruby)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero gem dependencies. Ruby 3.2+.

## Input model

- **Protojson `Hash`** — primary conformance path (string or symbol keys)
- **Duck-typed objects** — including `Google::Cloud::Spanner::V1` protobuf
  messages via `public_send` / `WhichOneof`

Values accept plain Ruby scalars, arrays for ARRAY/STRUCT wire shorthand, and
wire wrapper hashes (string keys for `string_value`, `list_value`, etc.).

Use `Spanvalue.encode_value(type, native_value)` to build wire values from
native Ruby types (`true`/`false`, `Integer`, `String`, binary `String`,
`nil`, `Array`, `Hash` for STRUCT). `Spanvalue.format_result_row` encodes
each column then formats the row. `Spanvalue::ClientTypeAdapter.adapt` converts
`Google::Cloud::Spanner` structural types via duck-typing (no client
dependency).

## Quick start

```ruby
require 'spanvalue'

typ = {
  code: 'STRUCT',
  structType: {
    fields: [
      { name: 'n', type: { code: 'INT64' } },
      { name: 's', type: { code: 'STRING' } },
    ],
  },
}
value = ['1', 'hello']

config = Spanvalue.literal_format_config
puts Spanvalue.format_value(typ, value, config)
# STRUCT<n INT64, s STRING>(1, "hello")
```

```ruby
wire = Spanvalue.encode_value({ code: 'INT64' }, 42)
row = Spanvalue.format_result_row(
  [{ code: 'INT64' }, { code: 'STRING' }],
  [42, 'hello'],
  Spanvalue.simple_format_config
)
# => ["42", "hello"]
```

## Tests

```bash
cd ruby && gem install minitest && ruby -Itest test/test_conformance.rb test/test_encoder.rb
```

Conformance cases load `../testdata/conformance.json`. Encoder unit tests are
in `test/test_encoder.rb`.

## Integration example

See [`../examples/ruby/`](../examples/ruby/) for a runnable Spanner emulator demo (`query_format.rb`) using `ClientTypeAdapter.adapt`, `encode_value`, and `format_result_row`. Setup: [`../examples/README.md`](../examples/README.md).

## License

MIT
