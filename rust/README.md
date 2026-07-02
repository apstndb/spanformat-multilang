# spanvalue (Rust)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero runtime dependencies. Rust stable.

## Input model

The public API accepts **native Rust types**:

- `spanvalue::Type` for type formatting
- `spanvalue::Value` for value formatting

Protobuf-shaped types can implement [`TypeLike`](src/proto_adapt.rs) /
[`ValueLike`](src/proto_adapt.rs) (no `prost` dependency) and use
`format_value_like`. Conformance tests use `serde_json` internally to parse
protojson into `Type`/`Value`.

High-level client row values are not supported — use the encoder below or
convert to wire form first.

## Wire encoders

[`encode_value`](src/encoder.rs) maps `NativeValue` + wire `Type` → `Value`.
[`format_result_row`](src/encoder.rs) encodes each column then calls
[`format_row`](src/format_config.rs).

```rust
use spanvalue::{
    encode_value, format_result_row, simple_format_config, type_from_parts, NativeValue,
};

let typ = type_from_parts(Some("INT64"), None, None, None, None);
let wire = encode_value(&typ, &NativeValue::I64(42)).unwrap();
```

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

## Integration example

See [`../examples/rust/`](../examples/rust/) for a runnable Spanner emulator demo using `encode_value` and `format_result_row` with the preview `google-cloud-spanner` Rust client. Setup: [`../examples/README.md`](../examples/README.md).

## License

MIT
