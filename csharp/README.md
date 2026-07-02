# spanvalue (C#)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero NuGet dependencies. .NET 8+.

## Input model

The public API accepts:

- `System.Text.Json.JsonElement` (conformance test path)
- `IReadOnlyDictionary<string, object?>`
- Duck-typed C# objects via property reflection (`code`/`Code`, etc.)
- **Protobuf messages** via reflection when `Google.Protobuf` /
  `Google.Cloud.Spanner.V1` types are present at runtime (`IMessage`,
  `WellKnownTypes.Value`, `GetCode()` / `KindCase` accessors). No package
  reference is required in this library.

High-level `Google.Cloud.Spanner` row types (`Struct`, typed column values) are
not accepted directly — use [`ValueEncoder.EncodeValue`](#wire-encoders) or
convert to wire `(Type, Value)` first.

## Wire encoders

[`ValueEncoder`](src/Apstndb.SpanValue/Encoder.cs) builds wire
`google.protobuf.Value` payloads from native column values, then formats rows:

```csharp
using Apstndb.SpanValue;

var types = new object?[] { /* wire Type per column */ };
var natives = new object?[] { 1L, null, "hello" };
var cells = ValueEncoder.FormatResultRow(types, natives, FormatConfigFactory.SimpleFormatConfig());
```

- `EncodeValue(type, native)` → wire `Value` (protojson-shaped `object?`)
- `FormatResultRow(types, nativeValues, config)` → `encode` + `FormatRow`
- `AdaptClientType(clientType)` → wire `Type` dict (best-effort for
  `Google.Cloud.Spanner.V1.Type` / `SpannerDbType` via reflection)

## Quick start

```csharp
using System.Text.Json;
using Apstndb.SpanValue;

var typ = JsonDocument.Parse("""
    {
      "code": "STRUCT",
      "structType": {
        "fields": [
          { "name": "n", "type": { "code": "INT64" } },
          { "name": "s", "type": { "code": "STRING" } }
        ]
      }
    }
    """).RootElement;

var value = JsonDocument.Parse("""["1", "hello"]""").RootElement;

var config = FormatConfigFactory.LiteralFormatConfig();
Console.WriteLine(ValueFormat.FormatValue(typ, value, config));
// STRUCT<n INT64, s STRING>(1, "hello")
```

## Tests

```bash
cd csharp && dotnet test
```

Conformance cases load `../testdata/conformance.json`.

## License

MIT
