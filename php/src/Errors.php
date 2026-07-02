<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

class SpanValueError extends \Exception
{
}

class MalformedWireError extends SpanValueError
{
}

class UnknownTypeError extends SpanValueError
{
}

class MismatchedFieldsError extends SpanValueError
{
}

class EmptyTypeFQNError extends SpanValueError
{
}

class UnexpectedComplexValueKindError extends SpanValueError
{
}

class EmptyNullStringError extends SpanValueError
{
}
