using System.Collections;
using System.Reflection;
using System.Text.Json;

namespace Apstndb.SpanValue;

internal static class Proto
{
    public static object? Get(object? obj, params string[] names)
    {
        if (obj is null)
            return null;

        if (obj is JsonElement je)
            return GetFromJson(je, names);

        if (obj is IReadOnlyDictionary<string, object?> dict)
        {
            foreach (var name in names)
            {
                if (dict.TryGetValue(name, out var val))
                    return val;
            }
            return null;
        }

        var fromProto = GetFromProtobuf(obj, names);
        if (fromProto is not null)
            return fromProto;

        var type = obj.GetType();
        foreach (var name in names)
        {
            var prop = type.GetProperty(name);
            if (prop is not null)
                return prop.GetValue(obj);
        }

        return null;
    }

    private static object? GetFromProtobuf(object obj, string[] names)
    {
        var type = obj.GetType();
        if (!IsProtobufMessage(type))
            return null;

        foreach (var name in names)
        {
            var val = InvokeProtobufGetter(type, obj, name);
            if (val is not null)
                return val;
        }

        return null;
    }

    private static bool IsProtobufMessage(Type type)
    {
        foreach (var iface in type.GetInterfaces())
        {
            if (iface.FullName == "Google.Protobuf.IMessage")
                return true;
        }
        return type.GetProperty("Descriptor") is not null;
    }

    private static object? InvokeProtobufGetter(Type type, object obj, string name)
    {
        foreach (var candidate in PropertyNameCandidates(name))
        {
            var hasMethod = type.GetMethod("Has" + candidate);
            if (hasMethod is not null)
            {
                if (hasMethod.Invoke(obj, null) is not true)
                    continue;
            }

            var getMethod = type.GetMethod("Get" + candidate) ?? type.GetMethod(candidate);
            if (getMethod is null)
                continue;

            var val = getMethod.Invoke(obj, null);
            if (val is not null)
                return val;
        }

        return null;
    }

    private static IEnumerable<string> PropertyNameCandidates(string name)
    {
        yield return Capitalize(ToCamelCase(name));
        yield return Capitalize(name);
        yield return name;
        yield return ToCamelCase(name);
    }

    private static string Capitalize(string s) =>
        string.IsNullOrEmpty(s) ? s : char.ToUpperInvariant(s[0]) + s[1..];

    private static string ToCamelCase(string snake)
    {
        if (!snake.Contains('_'))
            return snake;

        var parts = snake.Split('_', StringSplitOptions.RemoveEmptyEntries);
        if (parts.Length == 0)
            return snake;

        return parts[0] + string.Concat(parts.Skip(1).Select(Capitalize));
    }

    private static object? GetFromJson(JsonElement je, string[] names)
    {
        if (je.ValueKind != JsonValueKind.Object)
            return null;

        foreach (var name in names)
        {
            if (je.TryGetProperty(name, out var prop))
                return prop;
        }

        return null;
    }

    public static int TypeCode(object? typ) =>
        Codes.ParseTypeCode(Get(typ, "code", "Code"));

    public static int TypeAnnotation(object? typ) =>
        Codes.ParseTypeAnnotation(Get(typ, "type_annotation", "typeAnnotation", "TypeAnnotation"));

    public static string ProtoTypeFqn(object? typ) =>
        GetString(Get(typ, "proto_type_fqn", "protoTypeFqn", "ProtoTypeFqn")) ?? "";

    public static object? ArrayElementType(object? typ) =>
        Get(typ, "array_element_type", "arrayElementType", "ArrayElementType");

    public static object? StructType(object? typ) =>
        Get(typ, "struct_type", "structType", "StructType");

    public static IReadOnlyList<object?> StructFields(object? typ)
    {
        var st = StructType(typ);
        if (st is null)
            return Array.Empty<object?>();

        var fields = Get(st, "fields", "Fields");
        return AsList(fields);
    }

    public static string FieldName(object? field) =>
        GetString(Get(field, "name", "Name")) ?? "";

    public static object? FieldType(object? field) =>
        Get(field, "type", "Type");

    public static string ValueKind(object? value)
    {
        if (value is null)
            return "null";

        if (value is JsonElement je)
            return ValueKindFromJson(je);

        if (value is bool)
            return "bool";

        if (value is string)
            return "string";

        if (value is int or long or float or double or decimal)
            return "number";

        if (value is IReadOnlyList<object?>)
            return "list";

        if (value is IEnumerable enumerable and not string)
        {
            if (enumerable is not IDictionary)
                return "list";
        }

        if (IsProtobufValue(value))
            return ValueKindFromProtobufValue(value);

        if (value is IReadOnlyDictionary<string, object?> dict)
        {
            if (dict.ContainsKey("null_value") || dict.ContainsKey("nullValue"))
                return "null";
            if (dict.ContainsKey("bool_value") || dict.ContainsKey("boolValue"))
                return "bool";
            if (dict.ContainsKey("number_value") || dict.ContainsKey("numberValue"))
                return "number";
            if (dict.ContainsKey("string_value") || dict.ContainsKey("stringValue"))
                return "string";
            if (dict.ContainsKey("list_value") || dict.ContainsKey("listValue"))
                return "list";
            return "missing";
        }

        return "missing";
    }

    private static bool IsProtobufValue(object value)
    {
        var type = value.GetType();
        if (type.FullName == "Google.Protobuf.WellKnownTypes.Value")
            return true;
        return type.GetProperty("KindCase") is not null;
    }

    private static string ValueKindFromProtobufValue(object value)
    {
        var kindCase = value.GetType().GetProperty("KindCase")?.GetValue(value);
        var kindName = kindCase?.ToString() ?? "";
        return kindName switch
        {
            "NullValue" => "null",
            "BoolValue" => "bool",
            "NumberValue" => "number",
            "StringValue" => "string",
            "ListValue" => "list",
            _ => "missing",
        };
    }

    private static string ValueKindFromJson(JsonElement je)
    {
        return je.ValueKind switch
        {
            JsonValueKind.Null => "null",
            JsonValueKind.True or JsonValueKind.False => "bool",
            JsonValueKind.Number => "number",
            JsonValueKind.String => "string",
            JsonValueKind.Array => "list",
            JsonValueKind.Object => ValueKindFromJsonObject(je),
            _ => "missing",
        };
    }

    private static string ValueKindFromJsonObject(JsonElement je)
    {
        if (je.TryGetProperty("null_value", out _) || je.TryGetProperty("nullValue", out _))
            return "null";
        if (je.TryGetProperty("bool_value", out _) || je.TryGetProperty("boolValue", out _))
            return "bool";
        if (je.TryGetProperty("number_value", out _) || je.TryGetProperty("numberValue", out _))
            return "number";
        if (je.TryGetProperty("string_value", out _) || je.TryGetProperty("stringValue", out _))
            return "string";
        if (je.TryGetProperty("list_value", out _) || je.TryGetProperty("listValue", out _))
            return "list";
        return "missing";
    }

    public static bool IsNullValue(object? value) =>
        ValueKind(value) is "null" or "missing";

    public static bool BoolValue(object? value)
    {
        if (value is bool b)
            return b;

        if (value is JsonElement je)
        {
            if (je.ValueKind is JsonValueKind.True or JsonValueKind.False)
                return je.GetBoolean();
            if (je.TryGetProperty("bool_value", out var bv))
                return bv.GetBoolean();
            if (je.TryGetProperty("boolValue", out bv))
                return bv.GetBoolean();
        }

        var raw = Get(value, "bool_value", "boolValue");
        return raw is bool rb && rb;
    }

    public static double NumberValue(object? value)
    {
        if (value is double d)
            return d;
        if (value is float f)
            return f;
        if (value is int i)
            return i;
        if (value is long l)
            return l;

        if (value is JsonElement je)
        {
            if (je.ValueKind == JsonValueKind.Number)
                return je.GetDouble();
            if (je.TryGetProperty("number_value", out var nv))
                return nv.GetDouble();
            if (je.TryGetProperty("numberValue", out nv))
                return nv.GetDouble();
        }

        var raw = Get(value, "number_value", "numberValue");
        return Convert.ToDouble(raw);
    }

    public static string StringValue(object? value)
    {
        if (value is string s)
            return s;

        if (value is JsonElement je)
        {
            if (je.ValueKind == JsonValueKind.String)
                return je.GetString() ?? "";
            if (je.TryGetProperty("string_value", out var sv))
                return sv.GetString() ?? "";
            if (je.TryGetProperty("stringValue", out sv))
                return sv.GetString() ?? "";
        }

        return GetString(Get(value, "string_value", "stringValue")) ?? "";
    }

    public static IReadOnlyList<object?> ListValues(object? value)
    {
        if (value is IReadOnlyList<object?> list)
            return list;

        if (value is IEnumerable enumerable and not string)
        {
            if (enumerable is not IDictionary)
            {
                var items = new List<object?>();
                foreach (var item in enumerable)
                    items.Add(item);
                return items;
            }
        }

        if (value is JsonElement je)
            return ListValuesFromJson(je);

        if (IsProtobufValue(value!))
        {
            var lv = Get(value, "list_value", "listValue", "ListValue");
            if (lv is not null)
                return ListValues(lv);
        }

        var lv2 = Get(value, "list_value", "listValue");
        if (lv2 is null)
            return Array.Empty<object?>();

        var vals = Get(lv2, "values", "Values");
        return AsList(vals);
    }

    private static IReadOnlyList<object?> ListValuesFromJson(JsonElement je)
    {
        if (je.ValueKind == JsonValueKind.Array)
            return je.EnumerateArray().Cast<object?>().ToList();

        if (je.TryGetProperty("list_value", out var lv) || je.TryGetProperty("listValue", out lv))
        {
            if (lv.ValueKind == JsonValueKind.Array)
                return lv.EnumerateArray().Cast<object?>().ToList();
            if (lv.TryGetProperty("values", out var values) || lv.TryGetProperty("Values", out values))
                return values.EnumerateArray().Cast<object?>().ToList();
        }

        return Array.Empty<object?>();
    }

    private static IReadOnlyList<object?> AsList(object? fields)
    {
        if (fields is null)
            return Array.Empty<object?>();

        if (fields is IReadOnlyList<object?> list)
            return list;

        if (fields is JsonElement je && je.ValueKind == JsonValueKind.Array)
            return je.EnumerateArray().Cast<object?>().ToList();

        return Array.Empty<object?>();
    }

    private static string? GetString(object? value)
    {
        if (value is null)
            return null;
        if (value is string s)
            return s;
        if (value is JsonElement je && je.ValueKind == JsonValueKind.String)
            return je.GetString();
        return value.ToString();
    }
}
