using System.Globalization;

namespace Apstndb.SpanValue;

public enum Preset
{
    Simple = 0,
    Literal = 1,
    SpannerCli = 2,
}

public sealed record FormatConfig
{
    public Preset Preset { get; init; } = Preset.Simple;
    public string NullString { get; init; } = "<null>";
    public LiteralQuoteConfig QuoteConfig { get; init; } = new();

    public FormatConfig()
    {
        if (string.IsNullOrEmpty(NullString))
            throw new EmptyNullStringException("null_string must not be empty");
    }

    public FormatConfig(Preset preset, string nullString, LiteralQuoteConfig? quote = null)
        : this()
    {
        Preset = preset;
        NullString = nullString;
        QuoteConfig = Quote.NormalizeLiteralQuote(quote ?? new LiteralQuoteConfig());
        if (string.IsNullOrEmpty(NullString))
            throw new EmptyNullStringException("null_string must not be empty");
    }

    public FormatConfig WithNullString(string nullString)
    {
        if (string.IsNullOrEmpty(nullString))
            throw new EmptyNullStringException("null_string must not be empty");
        return this with { NullString = nullString };
    }
}

public static class FormatConfigFactory
{
    public static FormatConfig SimpleFormatConfig(string nullString = "<null>") =>
        new(Preset.Simple, nullString);

    public static FormatConfig LiteralFormatConfig(
        LiteralQuoteConfig? quote = null,
        string nullString = "NULL") =>
        new(Preset.Literal, nullString, Quote.NormalizeLiteralQuote(quote ?? new LiteralQuoteConfig()));

    public static FormatConfig SpannerCliFormatConfig(string nullString = "NULL") =>
        new(Preset.SpannerCli, nullString);
}

public static class ValueFormat
{
    private static bool IsComplexType(int code) =>
        code is (int)TypeCode.Array or (int)TypeCode.Struct;

    private static bool IsScalarType(int code) =>
        code is (int)TypeCode.Bool
            or (int)TypeCode.Int64
            or (int)TypeCode.Enum
            or (int)TypeCode.Float32
            or (int)TypeCode.Float64
            or (int)TypeCode.String
            or (int)TypeCode.Bytes
            or (int)TypeCode.Proto
            or (int)TypeCode.Timestamp
            or (int)TypeCode.Date
            or (int)TypeCode.Numeric
            or (int)TypeCode.Json
            or (int)TypeCode.Interval
            or (int)TypeCode.Uuid;

    public static string FormatValue(object? typ, object? value, FormatConfig config, bool toplevel = true)
    {
        if (Proto.IsNullValue(value))
            return config.NullString;

        var code = Proto.TypeCode(typ);

        if (code == (int)TypeCode.Array)
        {
            var elems = GetListValue(typ, value, code);
            var elemType = Proto.ArrayElementType(typ);
            var parts = elems.Select(elem => FormatValue(elemType, elem, config, toplevel: false)).ToList();
            var joined = string.Join(", ", parts);
            if (config.Preset == Preset.Literal && toplevel && IsComplexType(Proto.TypeCode(elemType)))
                return $"{TypeFormat.FormatTypeVerbose(typ)}[{joined}]";
            return $"[{joined}]";
        }

        if (code == (int)TypeCode.Struct)
        {
            var fieldVals = GetListValue(typ, value, code);
            var fields = Proto.StructFields(typ);
            if (fieldVals.Count != fields.Count)
                throw new MismatchedFieldsException($"got {fieldVals.Count} values, want {fields.Count}");

            if (config.Preset == Preset.Simple)
            {
                var fieldStrs = new List<string>(fields.Count);
                for (var i = 0; i < fields.Count; i++)
                {
                    var rendered = FormatValue(Proto.FieldType(fields[i]), fieldVals[i], config, toplevel: false);
                    var name = Proto.FieldName(fields[i]);
                    fieldStrs.Add(!string.IsNullOrEmpty(name) ? $"{rendered} AS {name}" : rendered);
                }

                return $"({string.Join(", ", fieldStrs)})";
            }

            var values = new List<string>(fields.Count);
            for (var i = 0; i < fields.Count; i++)
                values.Add(FormatValue(Proto.FieldType(fields[i]), fieldVals[i], config, toplevel: false));

            var inner = string.Join(", ", values);
            if (config.Preset == Preset.Literal)
            {
                var prefix = toplevel ? TypeFormat.FormatTypeVerbose(typ) : "";
                return $"{prefix}({inner})";
            }

            if (config.Preset == Preset.SpannerCli)
                return $"[{inner}]";

            return $"({inner})";
        }

        if (code == (int)TypeCode.Proto)
        {
            if (config.Preset == Preset.Literal)
                return FormatProtoLiteral(typ, value, config.QuoteConfig, config.NullString);

            RequireStringWire(value, code);
            return config.Preset == Preset.SpannerCli
                ? Proto.StringValue(value)
                : BytesFmt.ReadableStringFromBase64Wire(Proto.StringValue(value));
        }

        if (code == (int)TypeCode.Enum)
        {
            if (config.Preset == Preset.Literal)
                return FormatEnumLiteral(typ, value, config.NullString);
            return FormatEnumSimple(typ, value, config.NullString);
        }

        if (code == (int)TypeCode.TypeCodeUnspecified || !IsScalarType(code))
            throw new UnknownTypeException(typ?.ToString() ?? "null type");

        return config.Preset switch
        {
            Preset.Simple => FormatScalarSimple(typ, value),
            Preset.Literal => FormatScalarLiteral(typ, value, config.QuoteConfig),
            _ => FormatScalarSpannerCli(typ, value),
        };
    }

    public static IReadOnlyList<string> FormatRow(
        IReadOnlyList<object?> types,
        IReadOnlyList<object?> values,
        FormatConfig config)
    {
        if (types.Count != values.Count)
            throw new ArgumentException($"len(types)={types.Count} != len(values)={values.Count}");

        var result = new string[types.Count];
        for (var i = 0; i < types.Count; i++)
            result[i] = FormatValue(types[i], values[i], config, toplevel: true);
        return result;
    }

    private static IReadOnlyList<object?> GetListValue(object? typ, object? value, int expectedCode)
    {
        if (Proto.ValueKind(value) != "list")
        {
            throw new UnexpectedComplexValueKindException(
                $"unexpected complex value kind for {TypeFormat.FormatTypeCode(expectedCode)}: {Proto.ValueKind(value)}");
        }

        return Proto.ListValues(value);
    }

    private static void RequireStringWire(object? value, int code)
    {
        if (Proto.ValueKind(value) != "string")
            throw new MalformedWireException($"{TypeFormat.FormatTypeCode(code)} value kind {Proto.ValueKind(value)}");
    }

    private static void RequireBoolWire(object? value, int code)
    {
        if (Proto.ValueKind(value) != "bool")
            throw new MalformedWireException($"{TypeFormat.FormatTypeCode(code)} value kind {Proto.ValueKind(value)}");
    }

    private static void ValidateFloatWire(object? value, int code)
    {
        var kind = Proto.ValueKind(value);
        if (kind == "number")
            return;

        if (kind == "string")
        {
            var s = Proto.StringValue(value);
            if (s is "NaN" or "Infinity" or "-Infinity")
                return;
            throw new MalformedWireException($"{TypeFormat.FormatTypeCode(code)} unexpected float string {s}");
        }

        throw new MalformedWireException($"{TypeFormat.FormatTypeCode(code)} value kind {kind}");
    }

    private static void ValidateScalarWire(object? typ, object? value)
    {
        if (typ is null)
            throw new MalformedWireException($"nil type with value kind {Proto.ValueKind(value)}");

        if (Proto.IsNullValue(value))
            throw new MalformedWireException($"{TypeFormat.FormatTypeCode(Proto.TypeCode(typ))} unexpected null value");

        var code = Proto.TypeCode(typ);
        switch (code)
        {
            case (int)TypeCode.Bool:
                RequireBoolWire(value, code);
                break;
            case (int)TypeCode.Int64:
            case (int)TypeCode.Enum:
            case (int)TypeCode.String:
            case (int)TypeCode.Bytes:
            case (int)TypeCode.Proto:
            case (int)TypeCode.Timestamp:
            case (int)TypeCode.Date:
            case (int)TypeCode.Numeric:
            case (int)TypeCode.Interval:
            case (int)TypeCode.Uuid:
            case (int)TypeCode.Json:
                RequireStringWire(value, code);
                break;
            case (int)TypeCode.Float32:
            case (int)TypeCode.Float64:
                ValidateFloatWire(value, code);
                break;
            case (int)TypeCode.TypeCodeUnspecified:
                throw new UnknownTypeException(typ.ToString() ?? "");
            default:
                if (!IsScalarType(code))
                    throw new UnknownTypeException(typ.ToString() ?? "");
                break;
        }
    }

    private static double GcvFloat64(object? value)
    {
        var kind = Proto.ValueKind(value);
        if (kind == "number")
            return Proto.NumberValue(value);

        if (kind == "string")
        {
            var s = Proto.StringValue(value);
            return s switch
            {
                "NaN" => double.NaN,
                "Infinity" => double.PositiveInfinity,
                "-Infinity" => double.NegativeInfinity,
                _ => throw new MalformedWireException($"FLOAT64 unexpected float string {s}"),
            };
        }

        throw new MalformedWireException($"FLOAT64 value kind {kind}");
    }

    private static double GcvFloat32(object? value) => FloatFmt.NarrowFloat32(GcvFloat64(value));

    private static string TrimSpannerCliNumericFraction(string s)
    {
        if (!s.Contains('.'))
            return s;
        return s.TrimEnd('0').TrimEnd('.');
    }

    private static string NumericWireString(object? value) => Proto.StringValue(value);

    private static string StringBasedLiteral(string typeName, string payload, LiteralQuoteConfig quote) =>
        $"{typeName} {Quote.ToStringLiteral(payload, quote)}";

    private static string FormatScalarSimple(object? typ, object? value)
    {
        ValidateScalarWire(typ, value);
        var code = Proto.TypeCode(typ);

        return code switch
        {
            (int)TypeCode.Bool => Proto.BoolValue(value) ? "true" : "false",
            (int)TypeCode.Int64 or (int)TypeCode.Enum or (int)TypeCode.String or (int)TypeCode.Timestamp
                or (int)TypeCode.Date or (int)TypeCode.Json or (int)TypeCode.Interval or (int)TypeCode.Uuid
                => Proto.StringValue(value),
            (int)TypeCode.Float32 => FloatFmt.FormatGoG(GcvFloat32(value), 32),
            (int)TypeCode.Float64 => FloatFmt.FormatGoG(GcvFloat64(value), 64),
            (int)TypeCode.Bytes or (int)TypeCode.Proto => BytesFmt.ReadableStringFromBase64Wire(Proto.StringValue(value)),
            (int)TypeCode.Numeric => NumericWireString(value),
            _ => throw new UnknownTypeException(typ?.ToString() ?? ""),
        };
    }

    private static string FormatScalarLiteral(object? typ, object? value, LiteralQuoteConfig quote)
    {
        ValidateScalarWire(typ, value);
        var code = Proto.TypeCode(typ);

        switch (code)
        {
            case (int)TypeCode.Bool:
                return Proto.BoolValue(value) ? "true" : "false";
            case (int)TypeCode.Int64:
            {
                var s = Proto.StringValue(value);
                if (!long.TryParse(s, NumberStyles.Integer, CultureInfo.InvariantCulture, out var n))
                    throw new MalformedWireException($"invalid INT64 wire {s}");
                if (n < long.MinValue || n > long.MaxValue)
                    throw new MalformedWireException($"INT64 out of range {s}");
                return s;
            }
            case (int)TypeCode.Float32:
                return FloatFmt.Float32ToLiteral(GcvFloat32(value), quote);
            case (int)TypeCode.Float64:
                return FloatFmt.Float64ToLiteral(GcvFloat64(value), quote);
            case (int)TypeCode.String:
                return Quote.ToStringLiteral(Proto.StringValue(value), quote);
            case (int)TypeCode.Bytes or (int)TypeCode.Proto:
                return Quote.ToBytesLiteral(BytesFmt.DecodeBase64Wire(Proto.StringValue(value)), quote);
            case (int)TypeCode.Timestamp:
                return StringBasedLiteral("TIMESTAMP", Proto.StringValue(value), quote);
            case (int)TypeCode.Date:
                return StringBasedLiteral("DATE", Proto.StringValue(value), quote);
            case (int)TypeCode.Numeric:
                return StringBasedLiteral("NUMERIC", NumericWireString(value), quote);
            case (int)TypeCode.Json:
                return StringBasedLiteral("JSON", Proto.StringValue(value), quote);
            case (int)TypeCode.Interval:
                return Quote.SqlCastQuoted(Proto.StringValue(value), "INTERVAL", quote);
            case (int)TypeCode.Uuid:
                return Quote.SqlCastQuoted(Proto.StringValue(value), "UUID", quote);
            default:
                throw new UnknownTypeException(typ?.ToString() ?? "");
        }
    }

    private static string FormatScalarSpannerCli(object? typ, object? value)
    {
        ValidateScalarWire(typ, value);
        var code = Proto.TypeCode(typ);

        return code switch
        {
            (int)TypeCode.Bool => Proto.BoolValue(value) ? "true" : "false",
            (int)TypeCode.Int64 or (int)TypeCode.Enum or (int)TypeCode.String or (int)TypeCode.Bytes
                or (int)TypeCode.Proto or (int)TypeCode.Timestamp or (int)TypeCode.Date
                or (int)TypeCode.Interval or (int)TypeCode.Uuid or (int)TypeCode.Json
                => Proto.StringValue(value),
            (int)TypeCode.Float32 => FloatFmt.FormatSpannerCliFloat(GcvFloat32(value), 32),
            (int)TypeCode.Float64 => FloatFmt.FormatSpannerCliFloat(GcvFloat64(value), 64),
            (int)TypeCode.Numeric => TrimSpannerCliNumericFraction(NumericWireString(value)),
            _ => throw new UnknownTypeException(typ?.ToString() ?? ""),
        };
    }

    private static string FormatProtoLiteral(object? typ, object? value, LiteralQuoteConfig quote, string nullString)
    {
        if (Proto.TypeCode(typ) != (int)TypeCode.Proto)
            throw new UnknownTypeException(typ?.ToString() ?? "");

        if (Proto.IsNullValue(value))
            return nullString;

        RequireStringWire(value, (int)TypeCode.Proto);
        var data = BytesFmt.DecodeBase64Wire(Proto.StringValue(value));
        var fqn = Proto.ProtoTypeFqn(typ);
        if (string.IsNullOrEmpty(fqn))
            throw new EmptyTypeFQNException("empty type FQN for PROTO");

        return $"CAST({Quote.ToBytesLiteral(data, quote)} AS `{fqn}`)";
    }

    private static string FormatEnumLiteral(object? typ, object? value, string nullString)
    {
        if (Proto.TypeCode(typ) != (int)TypeCode.Enum)
            throw new UnknownTypeException(typ?.ToString() ?? "");

        if (Proto.IsNullValue(value))
            return nullString;

        RequireStringWire(value, (int)TypeCode.Enum);
        var s = Proto.StringValue(value);
        if (!long.TryParse(s, NumberStyles.Integer, CultureInfo.InvariantCulture, out _))
            throw new MalformedWireException($"failed to parse enum wire payload {s}");

        var fqn = Proto.ProtoTypeFqn(typ);
        if (string.IsNullOrEmpty(fqn))
            throw new EmptyTypeFQNException("empty type FQN for ENUM");

        return $"CAST({s} AS `{fqn}`)";
    }

    private static string FormatEnumSimple(object? typ, object? value, string nullString)
    {
        if (Proto.IsNullValue(value))
            return nullString;
        return FormatScalarSimple(typ, value);
    }
}
