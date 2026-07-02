namespace Apstndb.SpanValue;

public enum StructMode
{
    Base = 0,
    Recursive = 1,
    RecursiveWithName = 2,
}

public enum ProtoEnumMode
{
    Base = 0,
    Leaf = 1,
    Full = 2,
    LeafWithKind = 3,
    FullWithKind = 4,
}

public enum ArrayMode
{
    Base = 0,
    Recursive = 1,
}

public enum UnknownMode
{
    Unknown = 0,
    TypeCode = 1,
    Verbose = 2,
    Panic = 3,
}

public enum TypeAnnotationMode
{
    Suffix = 0,
    Omit = 1,
    Primary = 2,
}

public sealed record FormatOption(
    StructMode Struct = StructMode.Base,
    ProtoEnumMode Proto = ProtoEnumMode.Base,
    ProtoEnumMode Enum = ProtoEnumMode.Base,
    ArrayMode Array = ArrayMode.Base,
    UnknownMode Unknown = UnknownMode.Unknown,
    TypeAnnotationMode TypeAnnotation = TypeAnnotationMode.Suffix)
{
    public FormatOption WithTypeAnnotation(TypeAnnotationMode mode) =>
        this with { TypeAnnotation = mode };
}

public static class TypeFormat
{
    public static readonly FormatOption FormatOptionSimplest = new(
        Unknown: UnknownMode.TypeCode);

    public static readonly FormatOption FormatOptionSimple = new(
        Proto: ProtoEnumMode.Leaf,
        Enum: ProtoEnumMode.Leaf,
        Array: ArrayMode.Recursive);

    public static readonly FormatOption FormatOptionNormal = new(
        Struct: StructMode.Recursive,
        Proto: ProtoEnumMode.Leaf,
        Enum: ProtoEnumMode.Leaf,
        Array: ArrayMode.Recursive,
        Unknown: UnknownMode.Verbose);

    public static readonly FormatOption FormatOptionVerbose = new(
        Struct: StructMode.RecursiveWithName,
        Proto: ProtoEnumMode.Full,
        Enum: ProtoEnumMode.Full,
        Array: ArrayMode.Recursive,
        Unknown: UnknownMode.Verbose);

    public static readonly FormatOption FormatOptionMoreVerbose = new(
        Struct: StructMode.RecursiveWithName,
        Proto: ProtoEnumMode.FullWithKind,
        Enum: ProtoEnumMode.FullWithKind,
        Array: ArrayMode.Recursive,
        Unknown: UnknownMode.Verbose);

    public static string FormatTypeCode(int code, UnknownMode mode = UnknownMode.Verbose)
    {
        var name = Codes.TypeCodeName(code);
        if (name is not null)
            return name;

        return mode switch
        {
            UnknownMode.TypeCode => code.ToString(),
            UnknownMode.Verbose => $"UNKNOWN({code})",
            UnknownMode.Panic => throw new UnknownTypeException($"unknown TypeCode({code})"),
            _ => "UNKNOWN",
        };
    }

    public static string FormatProtoEnum(object? typ, ProtoEnumMode mode)
    {
        var code = Proto.TypeCode(typ);
        var fqn = Proto.ProtoTypeFqn(typ);
        var codeName = FormatTypeCode(code);

        return mode switch
        {
            ProtoEnumMode.Leaf => LastCut(fqn, "."),
            ProtoEnumMode.Full => fqn,
            ProtoEnumMode.LeafWithKind => $"{codeName}<{LastCut(fqn, ".")}>",
            ProtoEnumMode.FullWithKind => $"{codeName}<{fqn}>",
            _ => codeName,
        };
    }

    public static string FormatStructFields(IReadOnlyList<object?> fields, FormatOption option)
    {
        var parts = new List<string>(fields.Count);
        foreach (var field in fields)
        {
            var typeStr = FormatType(Proto.FieldType(field), option);
            var name = Proto.FieldName(field);
            if (option.Struct == StructMode.RecursiveWithName && !string.IsNullOrEmpty(name))
                parts.Add($"{name} {typeStr}");
            else
                parts.Add(typeStr);
        }

        return string.Join(", ", parts);
    }

    public static string FormatType(object? typ, FormatOption? option = null)
    {
        option ??= FormatOptionSimple;

        var ann = Proto.TypeAnnotation(typ);
        if (option.TypeAnnotation == TypeAnnotationMode.Omit)
            return FormatTypeImpl(typ, option);

        if (option.TypeAnnotation == TypeAnnotationMode.Primary)
        {
            if (ann != (int)TypeAnnotationCode.TypeAnnotationCodeUnspecified)
                return AnnotationName(ann);
            return FormatTypeImpl(typ, option);
        }

        return FormatTypeImpl(typ, option) + AnnotationSuffix(ann);
    }

    public static string FormatTypeSimplest(object? typ) => FormatType(typ, FormatOptionSimplest);
    public static string FormatTypeSimple(object? typ) => FormatType(typ, FormatOptionSimple);
    public static string FormatTypeNormal(object? typ) => FormatType(typ, FormatOptionNormal);
    public static string FormatTypeVerbose(object? typ) => FormatType(typ, FormatOptionVerbose);
    public static string FormatTypeMoreVerbose(object? typ) => FormatType(typ, FormatOptionMoreVerbose);

    public static string FormatTypeVerboseAnnotationOmit(object? typ) =>
        FormatType(typ, FormatOptionVerbose.WithTypeAnnotation(TypeAnnotationMode.Omit));

    public static string FormatTypeVerboseAnnotationPrimary(object? typ) =>
        FormatType(typ, FormatOptionVerbose.WithTypeAnnotation(TypeAnnotationMode.Primary));

    private static string FormatTypeImpl(object? typ, FormatOption option)
    {
        var code = Proto.TypeCode(typ);

        if (code == (int)TypeCode.Array && option.Array != ArrayMode.Base)
        {
            var elem = Proto.ArrayElementType(typ);
            return $"ARRAY<{FormatType(elem, option)}>";
        }

        if (code == (int)TypeCode.Proto)
            return FormatProtoEnum(typ, option.Proto);

        if (code == (int)TypeCode.Enum)
            return FormatProtoEnum(typ, option.Enum);

        if (code == (int)TypeCode.Struct && option.Struct != StructMode.Base)
            return $"STRUCT<{FormatStructFields(Proto.StructFields(typ), option)}>";

        return FormatTypeCode(code, option.Unknown);
    }

    private static string LastCut(string s, string sep)
    {
        var idx = s.LastIndexOf(sep, StringComparison.Ordinal);
        return idx >= 0 ? s[(idx + sep.Length)..] : s;
    }

    private static string AnnotationSuffix(int ann)
    {
        if (ann == (int)TypeAnnotationCode.TypeAnnotationCodeUnspecified)
            return "";

        var name = Codes.TypeAnnotationName(ann);
        return name is null ? $"({ann})" : $"({name})";
    }

    private static string AnnotationName(int ann)
    {
        var name = Codes.TypeAnnotationName(ann);
        return name ?? ann.ToString();
    }
}
