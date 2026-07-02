using System.Text.Json;

namespace Apstndb.SpanValue;

public enum TypeCode
{
    TypeCodeUnspecified = 0,
    Bool = 1,
    Int64 = 2,
    Float64 = 3,
    Float32 = 4,
    Timestamp = 5,
    Date = 6,
    String = 7,
    Bytes = 8,
    Array = 9,
    Struct = 10,
    Numeric = 11,
    Json = 12,
    Proto = 13,
    Enum = 14,
    Interval = 15,
    Uuid = 16,
}

public enum TypeAnnotationCode
{
    TypeAnnotationCodeUnspecified = 0,
    PgNumeric = 2,
    PgJsonb = 3,
    PgOid = 4,
}

internal static class Codes
{
    private static readonly Dictionary<int, string> TypeCodeNames = Enum.GetValues<TypeCode>()
        .ToDictionary(c => (int)c, c => c switch
        {
            TypeCode.TypeCodeUnspecified => "TYPE_CODE_UNSPECIFIED",
            _ => c.ToString().ToUpperInvariant(),
        });

    private static readonly Dictionary<int, string> TypeAnnotationNames = new()
    {
        [(int)TypeAnnotationCode.TypeAnnotationCodeUnspecified] = "TYPE_ANNOTATION_CODE_UNSPECIFIED",
        [(int)TypeAnnotationCode.PgNumeric] = "PG_NUMERIC",
        [(int)TypeAnnotationCode.PgJsonb] = "PG_JSONB",
        [(int)TypeAnnotationCode.PgOid] = "PG_OID",
    };

    private static readonly Dictionary<string, int> TypeCodeNamesByProto =
        TypeCodeNames.ToDictionary(kv => kv.Value, kv => kv.Key);

    public static string? TypeCodeName(int code) =>
        TypeCodeNames.GetValueOrDefault(code);

    public static string? TypeAnnotationName(int ann) =>
        TypeAnnotationNames.GetValueOrDefault(ann);

    private static readonly Dictionary<string, int> TypeAnnotationNamesByProto =
        TypeAnnotationNames.ToDictionary(kv => kv.Value, kv => kv.Key);

    public static int ParseTypeCode(object? value)
    {
        if (value is null)
            return (int)TypeCode.TypeCodeUnspecified;

        if (value is int i)
            return i;

        if (value is long l)
            return (int)l;

        if (value is JsonElement je)
            return ParseTypeCodeFromJson(je);

        if (value is string s)
        {
            if (int.TryParse(s, out var n))
                return n;
            if (TypeCodeNamesByProto.TryGetValue(s, out var code))
                return code;
            return (int)Enum.Parse<TypeCode>(s, ignoreCase: false);
        }

        throw new ArgumentException($"cannot parse type code from {value}");
    }

    private static int ParseTypeCodeFromJson(JsonElement je)
    {
        return je.ValueKind switch
        {
            JsonValueKind.Number => je.GetInt32(),
            JsonValueKind.String => ParseTypeCode(je.GetString()),
            _ => throw new ArgumentException($"cannot parse type code from {je}"),
        };
    }

    public static int ParseTypeAnnotation(object? value)
    {
        if (value is null)
            return (int)TypeAnnotationCode.TypeAnnotationCodeUnspecified;

        if (value is int i)
            return i;

        if (value is long l)
            return (int)l;

        if (value is JsonElement je)
            return ParseTypeAnnotationFromJson(je);

        if (value is string s)
        {
            if (int.TryParse(s, out var n))
                return n;
            if (TypeAnnotationNamesByProto.TryGetValue(s, out var ann))
                return ann;
            return (int)Enum.Parse<TypeAnnotationCode>(s, ignoreCase: false);
        }

        throw new ArgumentException($"cannot parse type annotation from {value}");
    }

    private static int ParseTypeAnnotationFromJson(JsonElement je)
    {
        return je.ValueKind switch
        {
            JsonValueKind.Number => je.GetInt32(),
            JsonValueKind.String => ParseTypeAnnotation(je.GetString()),
            _ => throw new ArgumentException($"cannot parse type annotation from {je}"),
        };
    }
}
