using System.Text.Json;

namespace Apstndb.SpanValue.Tests;

public class ConformanceTests
{
    private static readonly string ConformancePath = Path.Combine(
        AppContext.BaseDirectory, "testdata", "conformance.json");

    private static readonly Dictionary<string, FormatOption> TypePresets = new()
    {
        ["simplest"] = TypeFormat.FormatOptionSimplest,
        ["simple"] = TypeFormat.FormatOptionSimple,
        ["normal"] = TypeFormat.FormatOptionNormal,
        ["verbose"] = TypeFormat.FormatOptionVerbose,
        ["more_verbose"] = TypeFormat.FormatOptionMoreVerbose,
    };

    private static readonly Dictionary<string, LiteralQuoteConfig> QuotePolicies = new()
    {
        ["legacy_double"] = new(QuoteStrategy.Legacy, PreferredQuote.Double),
        ["legacy_single"] = new(QuoteStrategy.Legacy, PreferredQuote.Single),
        ["always_double"] = new(QuoteStrategy.Always, PreferredQuote.Double),
        ["always_single"] = new(QuoteStrategy.Always, PreferredQuote.Single),
        ["min_escape_double"] = new(QuoteStrategy.MinEscape, PreferredQuote.Double),
        ["min_escape_single"] = new(QuoteStrategy.MinEscape, PreferredQuote.Single),
    };

    private static JsonDocument LoadConformance() =>
        JsonDocument.Parse(File.ReadAllText(ConformancePath));

    private static string FormatTypePreset(string preset, JsonElement typ) =>
        preset switch
        {
            "verbose_annotation_omit" => TypeFormat.FormatTypeVerboseAnnotationOmit(typ),
            "verbose_annotation_primary" => TypeFormat.FormatTypeVerboseAnnotationPrimary(typ),
            _ => TypeFormat.FormatType(typ, TypePresets[preset]),
        };

    public static IEnumerable<object[]> TypePresetCases()
    {
        foreach (var preset in TypePresets.Keys.Append("verbose_annotation_omit").Append("verbose_annotation_primary"))
            yield return new object[] { preset };
    }

    [Theory]
    [MemberData(nameof(TypePresetCases))]
    public void TypeCases(string preset)
    {
        using var doc = LoadConformance();
        foreach (var caseEl in doc.RootElement.GetProperty("type_cases").EnumerateArray())
        {
            var name = caseEl.GetProperty("name").GetString();
            var typ = caseEl.GetProperty("type");
            var want = caseEl.GetProperty("expected").GetProperty(preset).GetString();
            var got = FormatTypePreset(preset, typ);
            Assert.Equal(want, got);
        }
    }

    [Theory]
    [InlineData("simple")]
    [InlineData("literal")]
    [InlineData("spanner_cli")]
    public void ValueCases(string preset)
    {
        var config = preset switch
        {
            "simple" => FormatConfigFactory.SimpleFormatConfig(),
            "literal" => FormatConfigFactory.LiteralFormatConfig(),
            _ => FormatConfigFactory.SpannerCliFormatConfig(),
        };

        using var doc = LoadConformance();
        foreach (var caseEl in doc.RootElement.GetProperty("value_cases").EnumerateArray())
        {
            var typ = caseEl.GetProperty("type");
            var value = caseEl.GetProperty("value");
            var want = caseEl.GetProperty("expected").GetProperty(preset).GetString();
            var got = ValueFormat.FormatValue(typ, value, config);
            Assert.Equal(want, got);
        }
    }

    public static IEnumerable<object[]> QuotePolicyCases()
    {
        foreach (var policy in QuotePolicies.Keys)
            yield return new object[] { policy };
    }

    [Theory]
    [MemberData(nameof(QuotePolicyCases))]
    public void ValueLiteralQuotes(string policyName)
    {
        var config = FormatConfigFactory.LiteralFormatConfig(QuotePolicies[policyName]);

        using var doc = LoadConformance();
        foreach (var caseEl in doc.RootElement.GetProperty("value_cases").EnumerateArray())
        {
            var typ = caseEl.GetProperty("type");
            var value = caseEl.GetProperty("value");
            var want = caseEl.GetProperty("expected").GetProperty("literal_quotes").GetProperty(policyName).GetString();
            var got = ValueFormat.FormatValue(typ, value, config);
            Assert.Equal(want, got);
        }
    }
}
