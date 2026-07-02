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

class ArrayElementError extends SpanValueError
{
    public function __construct(
        public readonly int $index,
        \Throwable $previous,
    ) {
        parent::__construct("array element {$index}: {$previous->getMessage()}", 0, $previous);
    }
}

class StructFieldError extends SpanValueError
{
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        \Throwable $previous,
    ) {
        $label = $name !== '' ? "struct field {$index} ({$name})" : "struct field {$index}";
        parent::__construct("{$label}: {$previous->getMessage()}", 0, $previous);
    }
}
