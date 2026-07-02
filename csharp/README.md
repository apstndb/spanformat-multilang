# spanvalue (C#)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero NuGet dependencies. .NET 8+.

## Input model

The public API accepts **protojson-shaped data** today:

- `System.Text.Json.JsonElement` (conformance test path)
- `IReadOnlyDictionary<string, object?>`
- Duck-typed C# objects via property reflection (`code`/`Code`,
  `structType`/`StructType`, etc.)

**Not supported yet:** direct adapters for `Google.Cloud.Spanner.V1.Type` or
`Google.Protobuf.WellKnownTypes.Value`. You can serialize protobuf messages to
JSON and pass the resulting `JsonElement`, or build dictionaries manually.

High-level `Google.Cloud.Spanner` row types (`Struct`, typed column values) are
not accepted — convert to wire `(Type, Value)` first.

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
