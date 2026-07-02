using System.Collections;
using System.Globalization;

namespace Apstndb.SpanValue;

public static class ValueEncoder
{
    public static object? EncodeValue(object? type, object? nativeValue)
    {
        if (IsNativeNull(nativeValue))
            return null;

        var code = Proto.TypeCode(type);

        if (code == (int)TypeCode.Array)
        {
            var elemType = Proto.ArrayElementType(type)
                ?? throw new MalformedWireException("ARRAY missing array_element_type");
            var elems = AsNativeList(nativeValue);
            var encoded = new object?[elems.Count];
            for (var i = 0; i < elems.Count; i++)
                encoded[i] = EncodeValue(elemType, elems[i]);
            return encoded;
        }

        if (code == (int)TypeCode.Struct)
        {
            var fields = Proto.StructFields(type);
            var elems = AsNativeList(nativeValue);
            if (elems.Count != fields.Count)
                throw new MismatchedFieldsException(
                    $"got {elems.Count} native field values, want {fields.Count}");

            var encoded = new object?[elems.Count];
            for (var i = 0; i < fields.Count; i++)
                encoded[i] = EncodeValue(Proto.FieldType(fields[i]), elems[i]);
            return encoded;
        }

        return EncodeScalar(type, code, nativeValue);
    }

    public static IReadOnlyList<string> FormatResultRow(
        IReadOnlyList<object?> types,
        IReadOnlyList<object?> nativeValues,
        FormatConfig config)
    {
        if (types.Count != nativeValues.Count)
            throw new ArgumentException(
                $"len(types)={types.Count} != len(values)={nativeValues.Count}");

        var wireValues = new object?[nativeValues.Count];
        for (var i = 0; i < nativeValues.Count; i++)
            wireValues[i] = EncodeValue(types[i], nativeValues[i]);
        return ValueFormat.FormatRow(types, wireValues, config);
    }

    /// <summary>
    /// Best-effort adapter for <c>Google.Cloud.Spanner.V1.Type</c> or
    /// <c>SpannerDbType</c> via reflection (no package dependency).
    /// </summary>
    public static Dictionary<string, object?> AdaptClientType(object clientType)
    {
        var typeName = clientType.GetType().FullName ?? "";
        if (typeName.EndsWith("SpannerDbType", StringComparison.Ordinal))
            return AdaptSpannerDbType(clientType);

        return AdaptWireTypeObject(clientType);
    }

    public static object? WireToNative(object? type, object? wire)
    {
        if (wire is null || Proto.IsNullValue(wire))
            return null;

        var code = Proto.TypeCode(type);

        if (code == (int)TypeCode.Array)
        {
            var elemType = Proto.ArrayElementType(type)!;
            return Proto.ListValues(wire)
                .Select(v => WireToNative(elemType, v))
                .ToList();
        }

        if (code == (int)TypeCode.Struct)
        {
            var fields = Proto.StructFields(type);
            var vals = Proto.ListValues(wire);
            if (vals.Count != fields.Count)
                throw new MismatchedFieldsException(
                    $"got {vals.Count} wire field values, want {fields.Count}");
            return fields.Zip(vals, (f, v) => WireToNative(Proto.FieldType(f), v)).ToList();
        }

        return WireToNativeScalar(code, wire);
    }

    private static object? WireToNativeScalar(int code, object? wire)
    {
        return code switch
        {
            (int)TypeCode.Bool => Proto.BoolValue(wire),
            (int)TypeCode.Int64 or (int)TypeCode.Enum =>
                long.TryParse(Proto.StringValue(wire), NumberStyles.Integer, CultureInfo.InvariantCulture, out var n)
                    ? n
                    : Proto.StringValue(wire),
            (int)TypeCode.Float32 or (int)TypeCode.Float64 => GcvFloat64(wire),
            (int)TypeCode.Bytes or (int)TypeCode.Proto =>
                BytesFmt.DecodeBase64Wire(Proto.StringValue(wire)),
            _ => Proto.StringValue(wire),
        };
    }

    private static object? EncodeScalar(object? type, int code, object? nativeValue)
    {
        return code switch
        {
            (int)TypeCode.Bool => nativeValue switch
            {
                bool b => b,
                _ => throw NativeTypeMismatch(code, nativeValue),
            },
            (int)TypeCode.Int64 or (int)TypeCode.Enum => nativeValue switch
            {
                long l => l.ToString(CultureInfo.InvariantCulture),
                int i => i.ToString(CultureInfo.InvariantCulture),
                string s => s,
                _ => throw NativeTypeMismatch(code, nativeValue),
            },
            (int)TypeCode.Float32 or (int)TypeCode.Float64 => EncodeFloat(nativeValue),
            (int)TypeCode.String or (int)TypeCode.Timestamp or (int)TypeCode.Date
                or (int)TypeCode.Numeric or (int)TypeCode.Json or (int)TypeCode.Interval
                or (int)TypeCode.Uuid => nativeValue as string
                ?? throw NativeTypeMismatch(code, nativeValue),
            (int)TypeCode.Bytes or (int)TypeCode.Proto => nativeValue switch
            {
                byte[] bytes => Convert.ToBase64String(bytes),
                string s => s,
                _ => throw NativeTypeMismatch(code, nativeValue),
            },
            _ => throw new UnknownTypeException(type?.ToString() ?? $"code {code}"),
        };
    }

    private static object EncodeFloat(object? nativeValue)
    {
        var v = nativeValue switch
        {
            double d => d,
            float f => f,
            int i => (double)i,
            long l => l,
            _ => throw NativeTypeMismatch((int)TypeCode.Float64, nativeValue),
        };

        if (double.IsNaN(v))
            return "NaN";
        if (double.IsPositiveInfinity(v))
            return "Infinity";
        if (double.IsNegativeInfinity(v))
            return "-Infinity";
        return v;
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
                _ => throw new MalformedWireException($"FLOAT unexpected string {s}"),
            };
        }

        throw new MalformedWireException($"FLOAT value kind {kind}");
    }

    private static bool IsNativeNull(object? value) =>
        value is null or DBNull;

    private static IReadOnlyList<object?> AsNativeList(object? value)
    {
        if (value is IReadOnlyList<object?> list)
            return list;

        if (value is IEnumerable enumerable and not string)
        {
            var items = new List<object?>();
            foreach (var item in enumerable)
                items.Add(item);
            return items;
        }

        throw new MalformedWireException($"expected list native value, got {value?.GetType().Name ?? "null"}");
    }

    private static MalformedWireException NativeTypeMismatch(int code, object? native) =>
        new($"native value {native?.GetType().Name ?? "null"} does not match type code {code}");

    private static Dictionary<string, object?> AdaptWireTypeObject(object clientType)
    {
        var code = Proto.TypeCode(clientType);
        var dict = new Dictionary<string, object?> { ["code"] = Codes.TypeCodeName(code) ?? code.ToString(CultureInfo.InvariantCulture) };

        var ann = Proto.TypeAnnotation(clientType);
        if (ann != (int)TypeAnnotationCode.TypeAnnotationCodeUnspecified)
            dict["typeAnnotation"] = Codes.TypeAnnotationName(ann) ?? ann.ToString(CultureInfo.InvariantCulture);

        var fqn = Proto.ProtoTypeFqn(clientType);
        if (!string.IsNullOrEmpty(fqn))
            dict["protoTypeFqn"] = fqn;

        var elem = Proto.ArrayElementType(clientType);
        if (elem is not null)
            dict["arrayElementType"] = AdaptWireTypeObject(elem);

        var st = Proto.StructType(clientType);
        if (st is not null)
        {
            var fields = Proto.StructFields(st);
            dict["structType"] = new Dictionary<string, object?>
            {
                ["fields"] = fields.Select(f => new Dictionary<string, object?>
                {
                    ["name"] = Proto.FieldName(f),
                    ["type"] = AdaptWireTypeObject(Proto.FieldType(f)!),
                }).ToList(),
            };
        }

        return dict;
    }

    private static Dictionary<string, object?> AdaptSpannerDbType(object dbType)
    {
        var type = dbType.GetType();
        var codeProp = type.GetProperty("TypeCode") ?? type.GetProperty("Code");
        var codeObj = codeProp?.GetValue(dbType);
        var code = codeObj switch
        {
            null => (int)TypeCode.TypeCodeUnspecified,
            int i => i,
            Enum e => Convert.ToInt32(e, CultureInfo.InvariantCulture),
            _ => Codes.ParseTypeCode(codeObj),
        };

        var dict = new Dictionary<string, object?>
        {
            ["code"] = Codes.TypeCodeName(code) ?? code.ToString(CultureInfo.InvariantCulture),
        };

        var elemProp = type.GetProperty("ArrayElementType");
        if (elemProp?.GetValue(dbType) is { } elem)
            dict["arrayElementType"] = AdaptClientType(elem);

        var structFieldsProp = type.GetProperty("StructFields");
        if (structFieldsProp?.GetValue(dbType) is IEnumerable fields)
        {
            var fieldDicts = new List<Dictionary<string, object?>>();
            foreach (var field in fields)
            {
                var ft = field.GetType();
                fieldDicts.Add(new Dictionary<string, object?>
                {
                    ["name"] = ft.GetProperty("Name")?.GetValue(field) as string ?? "",
                    ["type"] = AdaptClientType(ft.GetProperty("Type")?.GetValue(field)!),
                });
            }
            dict["structType"] = new Dictionary<string, object?> { ["fields"] = fieldDicts };
        }

        return dict;
    }
}
