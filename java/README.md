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

### `com.google.cloud.spanner.Type` adapter

Use `ClientTypeAdapter.adapt` (or `SpanValue.adaptClientType`) to convert the
high-level client `Type` to wire `google.spanner.v1.Type`. STRUCT fields are
mapped from `getStructFields()` to `struct_type.fields` (ordered list). The
main library uses reflection and does not require `google-cloud-spanner` at
runtime; adapter tests use it in test scope only.

High-level row values (`com.google.cloud.spanner.Struct`, typed getters) can
be formatted via `Gcvctor.encodeValue` / `SpanValue.formatResultRow` when you
have column `Type` metadata and native Java values.

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

// Encode native values, then format a result row
List<Object> types = List.of(
    Map.of("code", "INT64"),
    Map.of("code", "STRING"));
List<Object> nativeValues = List.of(42L, "east");
List<String> cells = SpanValue.formatResultRow(types, nativeValues, SpanValue.simpleFormatConfig());

// Adapt high-level client Type to wire proto
com.google.spanner.v1.Type wireType = ClientTypeAdapter.adapt(com.google.cloud.spanner.Type.int64());
```

Conformance tests also accept plain `List` values for STRUCT wire shorthand.

## Tests

```bash
cd java && mvn test
```

Conformance cases load `../testdata/conformance.json`.

## Integration example

See [`../examples/java/`](../examples/java/) for a runnable Spanner emulator demo (`QueryFormatExample`) using `SpanValue.adaptClientType`, `encodeValue`, and `formatResultRow`. Install the library first (`cd java && mvn install -DskipTests`). Setup: [`../examples/README.md`](../examples/README.md).

## License

MIT
