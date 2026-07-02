namespace Apstndb.SpanValue;

public class SpanValueException : Exception
{
    public SpanValueException(string message) : base(message) { }
}

public sealed class MalformedWireException : SpanValueException
{
    public MalformedWireException(string message) : base(message) { }
}

public sealed class UnknownTypeException : SpanValueException
{
    public UnknownTypeException(string message) : base(message) { }
}

public sealed class MismatchedFieldsException : SpanValueException
{
    public MismatchedFieldsException(string message) : base(message) { }
}

public sealed class EmptyTypeFQNException : SpanValueException
{
    public EmptyTypeFQNException(string message) : base(message) { }
}

public sealed class UnexpectedComplexValueKindException : SpanValueException
{
    public UnexpectedComplexValueKindException(string message) : base(message) { }
}

public sealed class EmptyNullStringException : SpanValueException
{
    public EmptyNullStringException(string message) : base(message) { }
}
