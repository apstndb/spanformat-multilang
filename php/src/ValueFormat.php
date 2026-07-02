<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

enum Preset: int
{
    case SIMPLE = 0;
    case LITERAL = 1;
    case SPANNER_CLI = 2;
}

final readonly class FormatConfig
{
    public function __construct(
        public Preset $preset = Preset::SIMPLE,
        public string $nullString = '<null>',
        public LiteralQuoteConfig $quote = new LiteralQuoteConfig(),
    ) {
        if ($this->nullString === '') {
            throw new EmptyNullStringError('null_string must not be empty');
        }
    }

    public function withNullString(string $nullString): self
    {
        return new self($this->preset, $nullString, $this->quote);
    }
}

final class ValueFormat
{
    public static function simpleFormatConfig(string $nullString = '<null>'): FormatConfig
    {
        return new FormatConfig(Preset::SIMPLE, $nullString);
    }

    public static function literalFormatConfig(
        ?LiteralQuoteConfig $quote = null,
        string $nullString = 'NULL',
    ): FormatConfig {
        $q = Quote::normalizeLiteralQuote($quote ?? new LiteralQuoteConfig());
        return new FormatConfig(Preset::LITERAL, $nullString, $q);
    }

    public static function spannerCliFormatConfig(string $nullString = 'NULL'): FormatConfig
    {
        return new FormatConfig(Preset::SPANNER_CLI, $nullString);
    }

    private static function isComplexType(int $code): bool
    {
        return $code === TypeCode::ARRAY->value || $code === TypeCode::STRUCT->value;
    }

    private static function isScalarType(int $code): bool
    {
        return in_array($code, [
            TypeCode::BOOL->value,
            TypeCode::INT64->value,
            TypeCode::ENUM->value,
            TypeCode::FLOAT32->value,
            TypeCode::FLOAT64->value,
            TypeCode::STRING->value,
            TypeCode::BYTES->value,
            TypeCode::PROTO->value,
            TypeCode::TIMESTAMP->value,
            TypeCode::DATE->value,
            TypeCode::NUMERIC->value,
            TypeCode::JSON->value,
            TypeCode::INTERVAL->value,
            TypeCode::UUID->value,
        ], true);
    }

    private static function requireStringWire(mixed $value, int $code): void
    {
        if (Proto::valueKind($value) !== 'string') {
            throw new MalformedWireError(
                self::formatTypeCodeName($code) . ' value kind ' . var_export(Proto::valueKind($value), true)
            );
        }
    }

    private static function requireBoolWire(mixed $value, int $code): void
    {
        if (Proto::valueKind($value) !== 'bool') {
            throw new MalformedWireError(
                self::formatTypeCodeName($code) . ' value kind ' . var_export(Proto::valueKind($value), true)
            );
        }
    }

    private static function validateFloatWire(mixed $value, int $code): void
    {
        $kind = Proto::valueKind($value);
        if ($kind === 'number') {
            return;
        }
        if ($kind === 'string') {
            $s = Proto::stringValue($value);
            if (in_array($s, ['NaN', 'Infinity', '-Infinity'], true)) {
                return;
            }
            throw new MalformedWireError(
                self::formatTypeCodeName($code) . ' unexpected float string ' . var_export($s, true)
            );
        }
        throw new MalformedWireError(
            self::formatTypeCodeName($code) . ' value kind ' . var_export($kind, true)
        );
    }

    public static function formatTypeCodeName(int $code): string
    {
        return TypeFormat::formatTypeCode($code);
    }

    private static function gcvFloat64(mixed $value): float
    {
        $kind = Proto::valueKind($value);
        if ($kind === 'number') {
            return Proto::numberValue($value);
        }
        if ($kind === 'string') {
            $s = Proto::stringValue($value);
            return match ($s) {
                'NaN' => NAN,
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => throw new MalformedWireError("FLOAT64 unexpected float string {$s}"),
            };
        }
        throw new MalformedWireError('FLOAT64 value kind ' . var_export($kind, true));
    }

    private static function gcvFloat32(mixed $value): float
    {
        return FloatFmt::narrowFloat32(self::gcvFloat64($value));
    }

    private static function validateScalarWire(mixed $typ, mixed $value): void
    {
        if ($typ === null) {
            throw new MalformedWireError('nil type with value kind ' . var_export(Proto::valueKind($value), true));
        }
        if (Proto::isNullValue($value)) {
            throw new MalformedWireError(
                self::formatTypeCodeName(Proto::typeCode($typ)) . ' unexpected null value'
            );
        }
        $code = Proto::typeCode($typ);
        if ($code === TypeCode::BOOL->value) {
            self::requireBoolWire($value, $code);
        } elseif (in_array($code, [
            TypeCode::INT64->value,
            TypeCode::ENUM->value,
            TypeCode::STRING->value,
            TypeCode::BYTES->value,
            TypeCode::PROTO->value,
            TypeCode::TIMESTAMP->value,
            TypeCode::DATE->value,
            TypeCode::NUMERIC->value,
            TypeCode::INTERVAL->value,
            TypeCode::UUID->value,
            TypeCode::JSON->value,
        ], true)) {
            self::requireStringWire($value, $code);
        } elseif ($code === TypeCode::FLOAT32->value || $code === TypeCode::FLOAT64->value) {
            self::validateFloatWire($value, $code);
        } elseif ($code === TypeCode::TYPE_CODE_UNSPECIFIED->value) {
            throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
        } elseif (!self::isScalarType($code)) {
            throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
        }
    }

    private static function isValidInt64Wire(string $s): bool
    {
        if (!preg_match('/^-?\d+$/', $s)) {
            return false;
        }
        $min = '-9223372036854775808';
        $max = '9223372036854775807';
        if (str_starts_with($s, '-')) {
            return strlen($s) < strlen($min) || (strlen($s) === strlen($min) && $s >= $min);
        }
        return strlen($s) < strlen($max) || (strlen($s) === strlen($max) && $s <= $max);
    }

    private static function trimSpannerCliNumericFraction(string $s): string
    {
        if (!str_contains($s, '.')) {
            return $s;
        }
        $s = rtrim($s, '0');
        return rtrim($s, '.');
    }

    private static function numericWireString(mixed $value): string
    {
        return Proto::stringValue($value);
    }

    private static function stringBasedLiteral(string $typeName, string $payload, LiteralQuoteConfig $quote): string
    {
        return "{$typeName} " . Quote::toStringLiteral($payload, $quote);
    }

    private static function formatScalarSimple(mixed $typ, mixed $value): string
    {
        self::validateScalarWire($typ, $value);
        $code = Proto::typeCode($typ);
        if ($code === TypeCode::BOOL->value) {
            return Proto::boolValue($value) ? 'true' : 'false';
        }
        if (in_array($code, [
            TypeCode::INT64->value,
            TypeCode::ENUM->value,
            TypeCode::STRING->value,
            TypeCode::TIMESTAMP->value,
            TypeCode::DATE->value,
            TypeCode::JSON->value,
            TypeCode::INTERVAL->value,
            TypeCode::UUID->value,
        ], true)) {
            return Proto::stringValue($value);
        }
        if ($code === TypeCode::FLOAT32->value) {
            return FloatFmt::formatGoG(self::gcvFloat32($value), 32);
        }
        if ($code === TypeCode::FLOAT64->value) {
            return FloatFmt::formatGoG(self::gcvFloat64($value), 64);
        }
        if ($code === TypeCode::BYTES->value || $code === TypeCode::PROTO->value) {
            return BytesFmt::readableStringFromBase64Wire(Proto::stringValue($value));
        }
        if ($code === TypeCode::NUMERIC->value) {
            return self::numericWireString($value);
        }
        throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
    }

    private static function formatScalarLiteral(mixed $typ, mixed $value, LiteralQuoteConfig $quote): string
    {
        self::validateScalarWire($typ, $value);
        $code = Proto::typeCode($typ);
        if ($code === TypeCode::BOOL->value) {
            return Proto::boolValue($value) ? 'true' : 'false';
        }
        if ($code === TypeCode::INT64->value) {
            $s = Proto::stringValue($value);
            if (!self::isValidInt64Wire($s)) {
                throw new MalformedWireError("invalid INT64 wire {$s}");
            }
            return $s;
        }
        if ($code === TypeCode::FLOAT32->value) {
            return FloatFmt::float32ToLiteral(self::gcvFloat32($value), $quote);
        }
        if ($code === TypeCode::FLOAT64->value) {
            return FloatFmt::float64ToLiteral(self::gcvFloat64($value), $quote);
        }
        if ($code === TypeCode::STRING->value) {
            return Quote::toStringLiteral(Proto::stringValue($value), $quote);
        }
        if ($code === TypeCode::BYTES->value || $code === TypeCode::PROTO->value) {
            $data = BytesFmt::decodeBase64Wire(Proto::stringValue($value));
            return Quote::toBytesLiteral($data, $quote);
        }
        if ($code === TypeCode::TIMESTAMP->value) {
            return self::stringBasedLiteral('TIMESTAMP', Proto::stringValue($value), $quote);
        }
        if ($code === TypeCode::DATE->value) {
            return self::stringBasedLiteral('DATE', Proto::stringValue($value), $quote);
        }
        if ($code === TypeCode::NUMERIC->value) {
            return self::stringBasedLiteral('NUMERIC', self::numericWireString($value), $quote);
        }
        if ($code === TypeCode::JSON->value) {
            return self::stringBasedLiteral('JSON', Proto::stringValue($value), $quote);
        }
        if ($code === TypeCode::INTERVAL->value) {
            return Quote::sqlCastQuoted(Proto::stringValue($value), 'INTERVAL', $quote);
        }
        if ($code === TypeCode::UUID->value) {
            return Quote::sqlCastQuoted(Proto::stringValue($value), 'UUID', $quote);
        }
        throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
    }

    private static function formatScalarSpannerCli(mixed $typ, mixed $value): string
    {
        self::validateScalarWire($typ, $value);
        $code = Proto::typeCode($typ);
        if ($code === TypeCode::BOOL->value) {
            return Proto::boolValue($value) ? 'true' : 'false';
        }
        if (in_array($code, [
            TypeCode::INT64->value,
            TypeCode::ENUM->value,
            TypeCode::STRING->value,
            TypeCode::BYTES->value,
            TypeCode::PROTO->value,
            TypeCode::TIMESTAMP->value,
            TypeCode::DATE->value,
            TypeCode::INTERVAL->value,
            TypeCode::UUID->value,
            TypeCode::JSON->value,
        ], true)) {
            return Proto::stringValue($value);
        }
        if ($code === TypeCode::FLOAT32->value) {
            return FloatFmt::formatSpannerCliFloat(self::gcvFloat32($value), 32);
        }
        if ($code === TypeCode::FLOAT64->value) {
            return FloatFmt::formatSpannerCliFloat(self::gcvFloat64($value), 64);
        }
        if ($code === TypeCode::NUMERIC->value) {
            return self::trimSpannerCliNumericFraction(self::numericWireString($value));
        }
        throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
    }

    private static function formatProtoLiteral(
        mixed $typ,
        mixed $value,
        LiteralQuoteConfig $quote,
        string $nullString,
    ): string {
        if (Proto::typeCode($typ) !== TypeCode::PROTO->value) {
            throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
        }
        if (Proto::isNullValue($value)) {
            return $nullString;
        }
        self::requireStringWire($value, TypeCode::PROTO->value);
        $data = BytesFmt::decodeBase64Wire(Proto::stringValue($value));
        $fqn = Proto::protoTypeFqn($typ);
        if ($fqn === '') {
            throw new EmptyTypeFQNError('empty type FQN for PROTO');
        }
        return 'CAST(' . Quote::toBytesLiteral($data, $quote) . " AS `{$fqn}`)";
    }

    private static function formatEnumLiteral(mixed $typ, mixed $value, string $nullString): string
    {
        if (Proto::typeCode($typ) !== TypeCode::ENUM->value) {
            throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
        }
        if (Proto::isNullValue($value)) {
            return $nullString;
        }
        self::requireStringWire($value, TypeCode::ENUM->value);
        $s = Proto::stringValue($value);
        if (!preg_match('/^-?\d+$/', $s)) {
            throw new MalformedWireError("failed to parse enum wire payload {$s}");
        }
        $fqn = Proto::protoTypeFqn($typ);
        if ($fqn === '') {
            throw new EmptyTypeFQNError('empty type FQN for ENUM');
        }
        return "CAST({$s} AS `{$fqn}`)";
    }

    private static function formatEnumSimple(mixed $typ, mixed $value, string $nullString): string
    {
        if (Proto::isNullValue($value)) {
            return $nullString;
        }
        return self::formatScalarSimple($typ, $value);
    }

    /** @return list<mixed> */
    private static function getListValue(mixed $typ, mixed $value, int $expectedCode): array
    {
        if (Proto::valueKind($value) !== 'list') {
            throw new UnexpectedComplexValueKindError(
                'unexpected complex value kind for '
                . self::formatTypeCodeName($expectedCode)
                . ': ' . var_export(Proto::valueKind($value), true)
            );
        }
        return Proto::listValues($value);
    }

    public static function formatValue(
        mixed $typ,
        mixed $value,
        FormatConfig $config,
        bool $toplevel = true,
    ): string {
        if (Proto::isNullValue($value)) {
            return $config->nullString;
        }

        $code = Proto::typeCode($typ);

        if ($code === TypeCode::ARRAY->value) {
            $elems = self::getListValue($typ, $value, $code);
            $elemType = Proto::arrayElementType($typ);
            $parts = [];
            foreach ($elems as $elem) {
                $parts[] = self::formatValue($elemType, $elem, $config, false);
            }
            $joined = implode(', ', $parts);
            if (
                $config->preset === Preset::LITERAL
                && $toplevel
                && self::isComplexType(Proto::typeCode($elemType))
            ) {
                return TypeFormat::formatTypeVerbose($typ) . "[{$joined}]";
            }
            return "[{$joined}]";
        }

        if ($code === TypeCode::STRUCT->value) {
            $fieldVals = self::getListValue($typ, $value, $code);
            $fields = Proto::structFields($typ);
            if (count($fieldVals) !== count($fields)) {
                throw new MismatchedFieldsError(
                    'got ' . count($fieldVals) . ' values, want ' . count($fields)
                );
            }
            if ($config->preset === Preset::SIMPLE) {
                $fieldStrs = [];
                foreach ($fields as $i => $field) {
                    $rendered = self::formatValue(Proto::fieldType($field), $fieldVals[$i], $config, false);
                    $name = Proto::fieldName($field);
                    if ($name !== '') {
                        $fieldStrs[] = "{$rendered} AS {$name}";
                    } else {
                        $fieldStrs[] = $rendered;
                    }
                }
                return '(' . implode(', ', $fieldStrs) . ')';
            }
            $fieldStrs = [];
            foreach ($fields as $i => $field) {
                $fieldStrs[] = self::formatValue(Proto::fieldType($field), $fieldVals[$i], $config, false);
            }
            $inner = implode(', ', $fieldStrs);
            if ($config->preset === Preset::LITERAL) {
                $prefix = $toplevel ? TypeFormat::formatTypeVerbose($typ) : '';
                return "{$prefix}({$inner})";
            }
            if ($config->preset === Preset::SPANNER_CLI) {
                return "[{$inner}]";
            }
            return "({$inner})";
        }

        if ($code === TypeCode::PROTO->value) {
            if ($config->preset === Preset::LITERAL) {
                return self::formatProtoLiteral($typ, $value, $config->quote, $config->nullString);
            }
            self::requireStringWire($value, $code);
            if ($config->preset === Preset::SPANNER_CLI) {
                return Proto::stringValue($value);
            }
            return BytesFmt::readableStringFromBase64Wire(Proto::stringValue($value));
        }

        if ($code === TypeCode::ENUM->value) {
            if ($config->preset === Preset::LITERAL) {
                return self::formatEnumLiteral($typ, $value, $config->nullString);
            }
            return self::formatEnumSimple($typ, $value, $config->nullString);
        }

        if ($code === TypeCode::TYPE_CODE_UNSPECIFIED->value || !self::isScalarType($code)) {
            throw new UnknownTypeError(json_encode($typ) ?: (string) $typ);
        }

        return match ($config->preset) {
            Preset::SIMPLE => self::formatScalarSimple($typ, $value),
            Preset::LITERAL => self::formatScalarLiteral($typ, $value, $config->quote),
            Preset::SPANNER_CLI => self::formatScalarSpannerCli($typ, $value),
        };
    }

    /** @param list<mixed> $types @param list<mixed> $values @return list<string> */
    public static function formatRow(array $types, array $values, FormatConfig $config): array
    {
        if (count($types) !== count($values)) {
            throw new \InvalidArgumentException(
                'len(types)=' . count($types) . ' != len(values)=' . count($values)
            );
        }
        $result = [];
        foreach ($types as $i => $type) {
            $result[] = self::formatValue($type, $values[$i], $config, true);
        }
        return $result;
    }
}
