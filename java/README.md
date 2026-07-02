# spanvalue (Java)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Depends on `proto-google-cloud-spanner-v1` and `protobuf-java` only — no
`google-cloud-spanner` client runtime. JDK 17+.

## Input model

**Wire protobuf messages** are the primary integration path:

- `com.google.spanner.v1.Type` for type formatting
- `com.google.protobuf.Value` for value formatting

Protojson `Map` objects (e.g. from Gson) also work and are used in conformance
tests.

### `com.google.cloud.spanner.Type` limitation

This library expects the **wire** `google.spanner.v1.Type` shape with
`struct_type.fields[]`. The high-level client type
`com.google.cloud.spanner.Type` uses a different STRUCT representation (field
name map API) and is **not supported**. Convert to wire proto or protojson
before formatting.

High-level row values (`com.google.cloud.spanner.Struct`, typed getters) are
also not accepted — use `com.google.protobuf.Value` from result metadata.

## Quick start

```java
import com.github.apstndb.spanvalue.SpanValue;
import com.google.spanner.v1.Type;
import com.google.spanner.v1.TypeCode;
import com.google.protobuf.Value;
import com.google.protobuf.ListValue;

Type typ = Type.newBuilder()
    .setCode(TypeCode.STRUCT)
    .setStructType(/* ... */)
    .build();
Value val = Value.newBuilder()
    .setListValue(ListValue.newBuilder()
        .addValues(Value.newBuilder().setStringValue("1"))
        .addValues(Value.newBuilder().setStringValue("hello")))
    .build();

var config = SpanValue.literalFormatConfig();
System.out.println(SpanValue.formatValue(typ, val, config));
```

Conformance tests also accept plain `List` values for STRUCT wire shorthand.

## Tests

```bash
cd java && mvn test
```

Conformance cases load `../testdata/conformance.json`.

## License

MIT
