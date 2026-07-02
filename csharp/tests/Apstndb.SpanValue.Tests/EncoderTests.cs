using System.Text.Json;
using System.Text.Json.Nodes;

namespace Apstndb.SpanValue.Tests;

public class EncoderTests
{
    private static readonly string ConformancePath = Path.Combine(
        AppContext.BaseDirectory, "testdata", "conformance.json");

    private static JsonDocument LoadConformance() =>
        JsonDocument.Parse(File.ReadAllText(ConformancePath));

    private static bool WireEqual(JsonElement expected, object? encoded)
    {
        var encodedJson = ToJsonNode(encoded);
        var expectedNode = JsonNode.Parse(expected.GetRawText());
        return WireNodesEqual(expectedNode, encodedJson);
    }

    private static bool WireNodesEqual(JsonNode? a, JsonNode? b)
    {
        if (a is null && b is null)
            return true;
        if (a is null || b is null)
            return false;

        if (a is JsonValue va && b is JsonValue vb)
        {
            if (va.TryGetValue<double>(out var da) && vb.TryGetValue<double>(out var db))
                return da == db;
            return va.ToJsonString() == vb.ToJsonString();
        }

        if (a is JsonArray aa && b is JsonArray ab)
        {
            if (aa.Count != ab.Count)
                return false;
            for (var i = 0; i < aa.Count; i++)
            {
                if (!WireNodesEqual(aa[i], ab[i]))
                    return false;
            }
            return true;
        }

        return JsonNode.DeepEquals(a, b);
    }

    private static JsonNode? ToJsonNode(object? value)
    {
        if (value is null)
            return null;

        return value switch
        {
            bool b => JsonValue.Create(b),
            string s => JsonValue.Create(s),
            double d => JsonValue.Create(d),
            float f => JsonValue.Create(f),
            int i => JsonValue.Create(i),
            long l => JsonValue.Create(l),
            JsonElement je => JsonNode.Parse(je.GetRawText()),
            IReadOnlyList<object?> list => new JsonArray(list.Select(ToJsonNode).ToArray()),
            _ => JsonNode.Parse(JsonSerializer.Serialize(value)),
        };
    }

    [Fact]
    public void EncoderRoundTripValueCases()
    {
        var config = FormatConfigFactory.SimpleFormatConfig();
        using var doc = LoadConformance();
        foreach (var caseEl in doc.RootElement.GetProperty("value_cases").EnumerateArray())
        {
            var name = caseEl.GetProperty("name").GetString();
            var typ = caseEl.GetProperty("type");
            var wire = caseEl.GetProperty("value");
            var wantFormatted = caseEl.GetProperty("expected").GetProperty("simple").GetString();

            var native = ValueEncoder.WireToNative(typ, wire);
            var encoded = ValueEncoder.EncodeValue(typ, native);
            Assert.True(WireEqual(wire, encoded), $"encoder mismatch for case {name}");

            var formatted = ValueFormat.FormatValue(typ, encoded!, config);
            Assert.Equal(wantFormatted, formatted);
        }
    }

    [Fact]
    public void FormatResultRowSmoke()
    {
        var boolType = JsonDocument.Parse("""{"code":"BOOL"}""").RootElement;
        var intType = JsonDocument.Parse("""{"code":"INT64"}""").RootElement;
        var strType = JsonDocument.Parse("""{"code":"STRING"}""").RootElement;
        var config = FormatConfigFactory.SimpleFormatConfig();

        var got = ValueEncoder.FormatResultRow(
            [boolType, intType, strType],
            [true, null, "ok"],
            config);

        Assert.Equal(["true", "<null>", "ok"], got);
    }

    [Fact]
    public void FormatResultRowStructAndNull()
    {
        var structType = JsonDocument.Parse("""
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
        var boolType = JsonDocument.Parse("""{"code":"BOOL"}""").RootElement;
        var config = FormatConfigFactory.SimpleFormatConfig();

        var got = ValueEncoder.FormatResultRow(
            [boolType, structType],
            [null, new object?[] { 42L, "x" }],
            config);

        Assert.Equal(["<null>", "(42 AS n, x AS s)"], got);
    }
}
