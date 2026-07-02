<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

final class Encoder
{
    /**
     * Build a wire `google.protobuf.Value` (protojson-compatible array/scalar) from a native value.
     *
     * @return mixed protojson Value: null, bool, float, string, or list of encoded elements
     */
    public static function encodeValue(mixed $type, mixed $nativeValue): mixed
    {
        if ($nativeValue === null) {
            return null;
        }

        $code = Proto::typeCode($type);

        if ($code === TypeCode::BOOL->value) {
            return self::encodeBool($nativeValue);
        }
        if ($code === TypeCode::INT64->value || $code === TypeCode::ENUM->value) {
            return self::encodeInt64Wire($nativeValue);
        }
        if ($code === TypeCode::FLOAT32->value || $code === TypeCode::FLOAT64->value) {
            return self::encodeFloatWire($nativeValue);
        }
        if (self::isStringWireCode($code)) {
            return self::encodeStringWire($nativeValue, $code);
        }
        if ($code === TypeCode::BYTES->value || $code === TypeCode::PROTO->value) {
            return self::encodeBytesWire($nativeValue);
        }
        if ($code === TypeCode::ARRAY->value) {
            return self::encodeArray($type, $nativeValue);
        }
        if ($code === TypeCode::STRUCT->value) {
            return self::encodeStruct($type, $nativeValue);
        }

        throw new UnknownTypeError(json_encode($type) ?: (string) $type);
    }

    /** @param list<mixed> $types @param list<mixed> $nativeValues @return list<string> */
    public static function formatResultRow(array $types, array $nativeValues, FormatConfig $config): array
    {
        if (count($types) !== count($nativeValues)) {
            throw new \InvalidArgumentException(
                'len(types)=' . count($types) . ' != len(values)=' . count($nativeValues)
            );
        }
        $wireValues = [];
        foreach ($types as $i => $type) {
            $wireValues[] = self::encodeValue($type, $nativeValues[$i]);
        }
        return ValueFormat::formatRow($types, $wireValues, $config);
    }

    private static function isStringWireCode(int $code): bool
    {
        return in_array($code, [
            TypeCode::STRING->value,
            TypeCode::TIMESTAMP->value,
            TypeCode::DATE->value,
            TypeCode::NUMERIC->value,
            TypeCode::JSON->value,
            TypeCode::INTERVAL->value,
            TypeCode::UUID->value,
        ], true);
    }

    private static function encodeBool(mixed $nativeValue): bool
    {
        if (is_bool($nativeValue)) {
            return $nativeValue;
        }
        if (is_array($nativeValue)) {
            return Proto::boolValue($nativeValue);
        }
        return (bool) $nativeValue;
    }

    private static function encodeInt64Wire(mixed $nativeValue): string
    {
        if (is_int($nativeValue)) {
            return (string) $nativeValue;
        }
        if (is_string($nativeValue)) {
            return $nativeValue;
        }
        if (is_array($nativeValue)) {
            return Proto::stringValue($nativeValue);
        }
        return (string) $nativeValue;
    }

    private static function encodeFloatWire(mixed $nativeValue): float|string
    {
        if (is_string($nativeValue)) {
            if (in_array($nativeValue, ['NaN', 'Infinity', '-Infinity'], true)) {
                return $nativeValue;
            }
            $f = (float) $nativeValue;
            if (is_finite($f)) {
                return $f;
            }
            return $nativeValue;
        }
        if (is_int($nativeValue) || is_float($nativeValue)) {
            $f = (float) $nativeValue;
            if (is_nan($f)) {
                return 'NaN';
            }
            if (is_infinite($f)) {
                return $f > 0 ? 'Infinity' : '-Infinity';
            }
            return $f;
        }
        if (is_array($nativeValue)) {
            $kind = Proto::valueKind($nativeValue);
            if ($kind === 'number') {
                return Proto::numberValue($nativeValue);
            }
            if ($kind === 'string') {
                return Proto::stringValue($nativeValue);
            }
        }
        throw new MalformedWireError('cannot encode float from ' . var_export($nativeValue, true));
    }

    private static function encodeStringWire(mixed $nativeValue, int $code): string
    {
        if (is_string($nativeValue)) {
            if ($code === TypeCode::JSON->value) {
                return self::jsonWireString($nativeValue);
            }
            return $nativeValue;
        }
        if (is_array($nativeValue) || is_object($nativeValue)) {
            if ($code === TypeCode::JSON->value) {
                return self::jsonWireString($nativeValue);
            }
        }
        if (is_array($nativeValue)) {
            return Proto::stringValue($nativeValue);
        }
        return (string) $nativeValue;
    }

    private static function jsonWireString(mixed $nativeValue): string
    {
        if (is_string($nativeValue)) {
            json_decode($nativeValue);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new MalformedWireError('invalid JSON input');
            }
            return $nativeValue;
        }
        $encoded = json_encode($nativeValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new MalformedWireError('JSON marshal failed');
        }
        return $encoded;
    }

    private static function encodeBytesWire(mixed $nativeValue): string
    {
        if (is_string($nativeValue)) {
            $decoded = base64_decode($nativeValue, true);
            if ($decoded !== false && base64_encode($decoded) === $nativeValue) {
                return $nativeValue;
            }
            return base64_encode($nativeValue);
        }
        if (is_array($nativeValue)) {
            return Proto::stringValue($nativeValue);
        }
        throw new MalformedWireError('cannot encode bytes from ' . var_export($nativeValue, true));
    }

    /** @return list<mixed> */
    private static function encodeArray(mixed $type, mixed $nativeValue): array
    {
        if (!is_array($nativeValue)) {
            throw new MalformedWireError('ARRAY native value must be a list');
        }
        if ($nativeValue !== [] && !array_is_list($nativeValue)) {
            throw new MalformedWireError('ARRAY native value must be a list');
        }
        $elemType = Proto::arrayElementType($type);
        $out = [];
        foreach ($nativeValue as $i => $elem) {
            try {
                $out[] = self::encodeValue($elemType, $elem);
            } catch (\Throwable $e) {
                throw new ArrayElementError($i, $e);
            }
        }
        return $out;
    }

    /** @return list<mixed> */
    private static function encodeStruct(mixed $type, mixed $nativeValue): array
    {
        if (!is_array($nativeValue)) {
            throw new MalformedWireError('STRUCT native value must be an array');
        }
        $fields = Proto::structFields($type);
        if ($nativeValue !== [] && !array_is_list($nativeValue)) {
            $out = [];
            foreach ($fields as $i => $field) {
                $name = Proto::fieldName($field);
                $elem = $name !== '' && array_key_exists($name, $nativeValue)
                    ? $nativeValue[$name]
                    : ($nativeValue[$i] ?? null);
                try {
                    $out[] = self::encodeValue(Proto::fieldType($field), $elem);
                } catch (\Throwable $e) {
                    throw new StructFieldError($i, $name, $e);
                }
            }
            return $out;
        }
        if (count($nativeValue) !== count($fields)) {
            throw new MismatchedFieldsError(
                'got ' . count($nativeValue) . ' values, want ' . count($fields)
            );
        }
        $out = [];
        foreach ($fields as $i => $field) {
            $name = Proto::fieldName($field);
            try {
                $out[] = self::encodeValue(Proto::fieldType($field), $nativeValue[$i]);
            } catch (\Throwable $e) {
                throw new StructFieldError($i, $name, $e);
            }
        }
        return $out;
    }

    /**
     * Convert a conformance/protojson wire value to a native PHP value for round-trip tests.
     */
    public static function wireToNative(mixed $type, mixed $wireValue): mixed
    {
        if ($wireValue === null || Proto::isNullValue($wireValue)) {
            return null;
        }

        $code = Proto::typeCode($type);

        if ($code === TypeCode::BOOL->value) {
            return Proto::boolValue($wireValue);
        }
        if ($code === TypeCode::INT64->value || $code === TypeCode::ENUM->value) {
            $s = Proto::stringValue($wireValue);
            if (preg_match('/^-?\d+$/', $s) && self::int64Fits($s)) {
                return (int) $s;
            }
            return $s;
        }
        if ($code === TypeCode::FLOAT32->value || $code === TypeCode::FLOAT64->value) {
            $kind = Proto::valueKind($wireValue);
            if ($kind === 'number') {
                return Proto::numberValue($wireValue);
            }
            return Proto::stringValue($wireValue);
        }
        if (self::isStringWireCode($code)) {
            return Proto::stringValue($wireValue);
        }
        if ($code === TypeCode::BYTES->value || $code === TypeCode::PROTO->value) {
            return BytesFmt::decodeBase64Wire(Proto::stringValue($wireValue));
        }
        if ($code === TypeCode::ARRAY->value) {
            $elemType = Proto::arrayElementType($type);
            $elems = Proto::listValues($wireValue);
            return array_map(
                static fn (mixed $v) => self::wireToNative($elemType, $v),
                $elems
            );
        }
        if ($code === TypeCode::STRUCT->value) {
            $fields = Proto::structFields($type);
            $fieldVals = Proto::listValues($wireValue);
            $out = [];
            foreach ($fields as $i => $field) {
                $out[] = self::wireToNative(Proto::fieldType($field), $fieldVals[$i] ?? null);
            }
            return $out;
        }

        throw new UnknownTypeError(json_encode($type) ?: (string) $type);
    }

    private static function int64Fits(string $s): bool
    {
        $min = '-9223372036854775808';
        $max = '9223372036854775807';
        if (str_starts_with($s, '-')) {
            return strlen($s) < strlen($min) || (strlen($s) === strlen($min) && $s >= $min);
        }
        return strlen($s) < strlen($max) || (strlen($s) === strlen($max) && $s <= $max);
    }

    /** Compare wire values after normalizing to protojson simplified form. */
    public static function wireEqual(mixed $type, mixed $a, mixed $b): bool
    {
        return self::normalizeWire($type, $a) === self::normalizeWire($type, $b);
    }

    private static function normalizeWire(mixed $type, mixed $value): mixed
    {
        if ($value === null || Proto::isNullValue($value)) {
            return null;
        }

        $code = Proto::typeCode($type);

        if ($code === TypeCode::BOOL->value) {
            return Proto::boolValue($value);
        }
        if ($code === TypeCode::INT64->value || $code === TypeCode::ENUM->value) {
            return Proto::stringValue($value);
        }
        if ($code === TypeCode::FLOAT32->value || $code === TypeCode::FLOAT64->value) {
            $kind = Proto::valueKind($value);
            if ($kind === 'number') {
                return Proto::numberValue($value);
            }
            return Proto::stringValue($value);
        }
        if (self::isStringWireCode($code) || $code === TypeCode::BYTES->value || $code === TypeCode::PROTO->value) {
            return Proto::stringValue($value);
        }
        if ($code === TypeCode::ARRAY->value) {
            $elemType = Proto::arrayElementType($type);
            return array_map(
                static fn (mixed $v) => self::normalizeWire($elemType, $v),
                Proto::listValues($value)
            );
        }
        if ($code === TypeCode::STRUCT->value) {
            $fields = Proto::structFields($type);
            $fieldVals = Proto::listValues($value);
            $out = [];
            foreach ($fields as $i => $field) {
                $out[] = self::normalizeWire(Proto::fieldType($field), $fieldVals[$i] ?? null);
            }
            return $out;
        }

        return $value;
    }
}
