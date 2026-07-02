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

High-level client row types are not accepted — convert to wire
`(Type, Value)` first.

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

## Tests

```bash
cd ruby && gem install minitest && ruby -Itest test/test_conformance.rb
```

Conformance cases load `../testdata/conformance.json`.

## License

MIT
