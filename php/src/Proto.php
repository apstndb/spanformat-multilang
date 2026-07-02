<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

final class Proto
{
    public static function get(mixed $obj, string ...$names): mixed
    {
        if ($obj === null) {
            return null;
        }
        if (is_array($obj)) {
            foreach ($names as $name) {
                if (array_key_exists($name, $obj)) {
                    return $obj[$name];
                }
            }
            return null;
        }
        foreach ($names as $name) {
            if (is_object($obj) && property_exists($obj, $name)) {
                return $obj->$name;
            }
        }
        if (is_object($obj) && method_exists($obj, 'get')) {
            foreach ($names as $name) {
                $val = $obj->get($name);
                if ($val !== null) {
                    return $val;
                }
            }
        }
        return null;
    }

    public static function typeCode(mixed $typ): int
    {
        return Codes::parseTypeCode(self::get($typ, 'code', 'Code'));
    }

    public static function typeAnnotation(mixed $typ): int
    {
        return Codes::parseTypeAnnotation(
            self::get($typ, 'type_annotation', 'typeAnnotation', 'TypeAnnotation')
        );
    }

    public static function protoTypeFqn(mixed $typ): string
    {
        $fqn = self::get($typ, 'proto_type_fqn', 'protoTypeFqn', 'ProtoTypeFqn');
        return $fqn !== null ? (string) $fqn : '';
    }

    public static function arrayElementType(mixed $typ): mixed
    {
        return self::get($typ, 'array_element_type', 'arrayElementType', 'ArrayElementType');
    }

    public static function structType(mixed $typ): mixed
    {
        return self::get($typ, 'struct_type', 'structType', 'StructType');
    }

    /** @return list<mixed> */
    public static function structFields(mixed $typ): array
    {
        $st = self::structType($typ);
        if ($st === null) {
            return [];
        }
        $fields = self::get($st, 'fields', 'Fields');
        if ($fields === null) {
            return [];
        }
        return is_array($fields) ? array_values($fields) : [];
    }

    public static function fieldName(mixed $field): string
    {
        $name = self::get($field, 'name', 'Name');
        return $name !== null ? (string) $name : '';
    }

    public static function fieldType(mixed $field): mixed
    {
        return self::get($field, 'type', 'Type');
    }

    public static function valueKind(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            if (array_key_exists('null_value', $value) || array_key_exists('nullValue', $value)) {
                return 'null';
            }
            if (array_key_exists('bool_value', $value) || array_key_exists('boolValue', $value)) {
                return 'bool';
            }
            if (array_key_exists('number_value', $value) || array_key_exists('numberValue', $value)) {
                return 'number';
            }
            if (array_key_exists('string_value', $value) || array_key_exists('stringValue', $value)) {
                return 'string';
            }
            if (array_key_exists('list_value', $value) || array_key_exists('listValue', $value)) {
                return 'list';
            }
            if (array_is_list($value)) {
                return 'list';
            }
            return 'missing';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value) || $value instanceof \Traversable) {
            return 'list';
        }
        if (is_object($value) && method_exists($value, 'WhichOneof')) {
            $which = $value->WhichOneof('kind');
            if ($which === null) {
                return 'missing';
            }
            return match ($which) {
                'null_value', 'nullValue' => 'null',
                'bool_value', 'boolValue' => 'bool',
                'number_value', 'numberValue' => 'number',
                'string_value', 'stringValue' => 'string',
                'list_value', 'listValue' => 'list',
                default => 'missing',
            };
        }
        foreach (
            [
                ['bool_value', 'bool'],
                ['boolValue', 'bool'],
                ['number_value', 'number'],
                ['numberValue', 'number'],
                ['string_value', 'string'],
                ['stringValue', 'string'],
                ['list_value', 'list'],
                ['listValue', 'list'],
            ] as [$attr, $kind]
        ) {
            if (is_object($value) && property_exists($value, $attr)) {
                $val = $value->$attr;
                if ($val !== null || $kind === 'null') {
                    return $kind;
                }
            }
        }
        return 'missing';
    }

    public static function isNullValue(mixed $value): bool
    {
        $kind = self::valueKind($value);
        return $kind === 'null' || $kind === 'missing';
    }

    public static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            return (bool) ($value['bool_value'] ?? $value['boolValue'] ?? false);
        }
        return (bool) self::get($value, 'bool_value', 'boolValue');
    }

    public static function numberValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) ($value['number_value'] ?? $value['numberValue']);
        }
        return (float) self::get($value, 'number_value', 'numberValue');
    }

    public static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return (string) ($value['string_value'] ?? $value['stringValue'] ?? '');
        }
        $sv = self::get($value, 'string_value', 'stringValue');
        return $sv !== null ? (string) $sv : '';
    }

    /** @return list<mixed> */
    public static function listValues(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return array_values($value);
        }
        if (is_array($value)) {
            $lv = $value['list_value'] ?? $value['listValue'] ?? null;
            if (is_array($lv)) {
                $vals = $lv['values'] ?? $lv['Values'] ?? [];
                return is_array($vals) ? array_values($vals) : [];
            }
            if (is_object($lv) && property_exists($lv, 'values')) {
                return array_values((array) $lv->values);
            }
        }
        $lv = self::get($value, 'list_value', 'listValue');
        if ($lv !== null) {
            $vals = self::get($lv, 'values', 'Values');
            return is_array($vals) ? array_values($vals) : [];
        }
        return [];
    }
}
