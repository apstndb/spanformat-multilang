# spanvalue (Rust)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero runtime dependencies. Rust stable.

## Input model

The public API accepts **native Rust types only**:

- `spanvalue::Type` for type formatting
- `spanvalue::Value` for value formatting

There are no built-in adapters for prost/protobuf messages or
`google-cloud-spanner` types. Conformance tests use `serde_json` internally to
parse protojson into `Type`/`Value`; callers must perform that conversion
themselves (or build `Type`/`Value` directly via `type_from_parts` and the
`Value` enum variants).

High-level client row values are not supported — convert to wire form first.

## Quick start

```rust
use spanvalue::{
    format_value, literal_format_config, type_from_parts, Field, StructType, Type, Value,
};

let typ = type_from_parts(
    Some("STRUCT"),
    None,
    Some(StructType {
        fields: vec![
            Field {
                name: "n".into(),
                field_type: type_from_parts(Some("INT64"), None, None, None, None),
            },
            Field {
                name: "s".into(),
                field_type: type_from_parts(Some("STRING"), None, None, None, None),
            },
        ],
    }),
    None,
    None,
);
let value = Value::List(vec![
    Value::String("1".into()),
    Value::String("hello".into()),
]);

let config = literal_format_config(None, "NULL");
println!("{}", format_value(&typ, &value, &config).unwrap());
// STRUCT<n INT64, s STRING>(1, "hello")
```

## Tests

```bash
cd rust && cargo test
```

Conformance cases load `../testdata/conformance.json`.

## License

MIT
