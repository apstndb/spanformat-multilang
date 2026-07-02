<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

enum TypeCode: int
{
    case TYPE_CODE_UNSPECIFIED = 0;
    case BOOL = 1;
    case INT64 = 2;
    case FLOAT64 = 3;
    case FLOAT32 = 4;
    case TIMESTAMP = 5;
    case DATE = 6;
    case STRING = 7;
    case BYTES = 8;
    case ARRAY = 9;
    case STRUCT = 10;
    case NUMERIC = 11;
    case JSON = 12;
    case PROTO = 13;
    case ENUM = 14;
    case INTERVAL = 15;
    case UUID = 16;

    /** @var array<int, string> */
    private const NAMES = [
        0 => 'TYPE_CODE_UNSPECIFIED',
        1 => 'BOOL',
        2 => 'INT64',
        3 => 'FLOAT64',
        4 => 'FLOAT32',
        5 => 'TIMESTAMP',
        6 => 'DATE',
        7 => 'STRING',
        8 => 'BYTES',
        9 => 'ARRAY',
        10 => 'STRUCT',
        11 => 'NUMERIC',
        12 => 'JSON',
        13 => 'PROTO',
        14 => 'ENUM',
        15 => 'INTERVAL',
        16 => 'UUID',
    ];

    public static function nameOf(int $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }
}

enum TypeAnnotationCode: int
{
    case TYPE_ANNOTATION_CODE_UNSPECIFIED = 0;
    case PG_NUMERIC = 2;
    case PG_JSONB = 3;
    case PG_OID = 4;

    /** @var array<int, string> */
    private const NAMES = [
        0 => 'TYPE_ANNOTATION_CODE_UNSPECIFIED',
        2 => 'PG_NUMERIC',
        3 => 'PG_JSONB',
        4 => 'PG_OID',
    ];

    public static function nameOf(int $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }
}

final class Codes
{
    public static function parseTypeCode(mixed $value): int
    {
        if ($value === null) {
            return TypeCode::TYPE_CODE_UNSPECIFIED->value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                return (int) $value;
            }
            return constant(TypeCode::class . '::' . $value)->value;
        }
        if (is_object($value) && property_exists($value, 'name')) {
            return constant(TypeCode::class . '::' . $value->name)->value;
        }
        if (is_object($value) && property_exists($value, 'value')) {
            return (int) $value->value;
        }
        throw new \TypeError('cannot parse type code from ' . var_export($value, true));
    }

    public static function parseTypeAnnotation(mixed $value): int
    {
        if ($value === null) {
            return TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED->value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
                return (int) $value;
            }
            return constant(TypeAnnotationCode::class . '::' . $value)->value;
        }
        if (is_object($value) && property_exists($value, 'name')) {
            return constant(TypeAnnotationCode::class . '::' . $value->name)->value;
        }
        if (is_object($value) && property_exists($value, 'value')) {
            return (int) $value->value;
        }
        throw new \TypeError('cannot parse type annotation from ' . var_export($value, true));
    }
}
