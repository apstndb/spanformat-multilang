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
        if (!is_object($obj)) {
            return null;
        }

        $fromKnown = self::getFromKnownProto($obj, $names);
        if ($fromKnown !== self::NOT_FOUND) {
            return $fromKnown;
        }

        foreach ($names as $name) {
            $val = self::invokeGetter($obj, $name);
            if ($val !== null) {
                return $val;
            }
        }
        foreach ($names as $name) {
            if (self::hasPublicProperty($obj, $name)) {
                $val = $obj->$name;
                if ($val !== null) {
                    return $val;
                }
            }
        }
        if (method_exists($obj, 'get')) {
            foreach ($names as $name) {
                $val = $obj->get($name);
                if ($val !== null) {
                    return $val;
                }
            }
        }
        return null;
    }

    private static function hasPublicProperty(object $obj, string $name): bool
    {
        if (!property_exists($obj, $name)) {
            return false;
        }
        $ref = new \ReflectionProperty($obj, $name);
        return $ref->isPublic();
    }

    private const NOT_FOUND = "\0not_found\0";

    /** @param list<string> $names */
    private static function getFromKnownProto(object $obj, array $names): mixed
    {
        $class = $obj::class;
        if (
            str_ends_with($class, '\\Type')
            || $class === 'Google\\Cloud\\Spanner\\V1\\Type'
            || $class === 'Google\\Spanner\\V1\\Type'
        ) {
            return self::getFromType($obj, $names);
        }
        if (
            str_ends_with($class, '\\Value')
            || $class === 'Google\\Protobuf\\Value'
        ) {
            return self::getFromValue($obj, $names);
        }
        if (str_ends_with($class, '\\Field') && str_contains($class, 'StructType')) {
            return self::getFromStructField($obj, $names);
        }
        if (str_ends_with($class, '\\ListValue') || $class === 'Google\\Protobuf\\ListValue') {
            return self::getFromListValue($obj, $names);
        }
        if (str_ends_with($class, '\\StructType')) {
            return self::getFromStructType($obj, $names);
        }
        return self::NOT_FOUND;
    }

    /** @param list<string> $names */
    private static function getFromType(object $type, array $names): mixed
    {
        foreach ($names as $name) {
            switch ($name) {
                case 'code':
                case 'Code':
                    if (method_exists($type, 'getCode')) {
                        return $type->getCode();
                    }
                    break;
                case 'type_annotation':
                case 'typeAnnotation':
                case 'TypeAnnotation':
                    if (method_exists($type, 'getTypeAnnotation')) {
                        return $type->getTypeAnnotation();
                    }
                    break;
                case 'proto_type_fqn':
                case 'protoTypeFqn':
                case 'ProtoTypeFqn':
                    if (method_exists($type, 'getProtoTypeFqn')) {
                        return $type->getProtoTypeFqn();
                    }
                    break;
                case 'array_element_type':
                case 'arrayElementType':
                case 'ArrayElementType':
                    if (method_exists($type, 'hasArrayElementType') && $type->hasArrayElementType()) {
                        return $type->getArrayElementType();
                    }
                    if (method_exists($type, 'getArrayElementType')) {
                        $val = $type->getArrayElementType();
                        if ($val !== null) {
                            return $val;
                        }
                    }
                    break;
                case 'struct_type':
                case 'structType':
                case 'StructType':
                    if (method_exists($type, 'hasStructType') && $type->hasStructType()) {
                        return $type->getStructType();
                    }
                    if (method_exists($type, 'getStructType')) {
                        $val = $type->getStructType();
                        if ($val !== null) {
                            return $val;
                        }
                    }
                    break;
            }
        }
        return self::NOT_FOUND;
    }

    /** @param list<string> $names */
    private static function getFromStructField(object $field, array $names): mixed
    {
        foreach ($names as $name) {
            switch ($name) {
                case 'name':
                case 'Name':
                    if (method_exists($field, 'getName')) {
                        return $field->getName();
                    }
                    break;
                case 'type':
                case 'Type':
                    if (method_exists($field, 'hasType') && $field->hasType()) {
                        return $field->getType();
                    }
                    if (method_exists($field, 'getType')) {
                        $val = $field->getType();
                        if ($val !== null) {
                            return $val;
                        }
                    }
                    break;
            }
        }
        return self::NOT_FOUND;
    }

    /** @param list<string> $names */
    private static function getFromStructType(object $structType, array $names): mixed
    {
        foreach ($names as $name) {
            if ($name === 'fields' || $name === 'Fields') {
                if (method_exists($structType, 'getFields')) {
                    return iterator_to_array($structType->getFields());
                }
                if (method_exists($structType, 'getFieldsList')) {
                    return $structType->getFieldsList();
                }
            }
        }
        return self::NOT_FOUND;
    }

    /** @param list<string> $names */
    private static function getFromListValue(object $listValue, array $names): mixed
    {
        foreach ($names as $name) {
            if ($name === 'values' || $name === 'Values') {
                if (method_exists($listValue, 'getValues')) {
                    return iterator_to_array($listValue->getValues());
                }
                if (method_exists($listValue, 'getValuesList')) {
                    return $listValue->getValuesList();
                }
            }
        }
        return self::NOT_FOUND;
    }

    /** @param list<string> $names */
    private static function getFromValue(object $value, array $names): mixed
    {
        foreach ($names as $name) {
            switch ($name) {
                case 'null_value':
                case 'nullValue':
                    if (method_exists($value, 'hasNullValue') && $value->hasNullValue()) {
                        return $value->getNullValue();
                    }
                    break;
                case 'bool_value':
                case 'boolValue':
                    if (method_exists($value, 'hasBoolValue') && $value->hasBoolValue()) {
                        return $value->getBoolValue();
                    }
                    break;
                case 'number_value':
                case 'numberValue':
                    if (method_exists($value, 'hasNumberValue') && $value->hasNumberValue()) {
                        return $value->getNumberValue();
                    }
                    break;
                case 'string_value':
                case 'stringValue':
                    if (method_exists($value, 'hasStringValue') && $value->hasStringValue()) {
                        return $value->getStringValue();
                    }
                    break;
                case 'list_value':
                case 'listValue':
                    if (method_exists($value, 'hasListValue') && $value->hasListValue()) {
                        return $value->getListValue();
                    }
                    break;
            }
        }
        return self::NOT_FOUND;
    }

    private static function invokeGetter(object $obj, string $name): mixed
    {
        $camel = self::toCamelCase($name);
        foreach ([$name, $camel, self::capitalize($name), self::capitalize($camel)] as $candidate) {
            $getter = 'get' . self::capitalize($candidate);
            $has = 'has' . self::capitalize($candidate);
            if (method_exists($obj, $has)) {
                if ($obj->$has()) {
                    return $obj->$getter();
                }
                continue;
            }
            if (method_exists($obj, $getter)) {
                $val = $obj->$getter();
                if ($val !== null) {
                    return $val;
                }
            }
        }
        return null;
    }

    private static function capitalize(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        return strtoupper($s[0]) . substr($s, 1);
    }

    private static function toCamelCase(string $snake): string
    {
        if (!str_contains($snake, '_')) {
            return $snake;
        }
        $parts = explode('_', $snake);
        $first = array_shift($parts);
        $out = $first ?? '';
        foreach ($parts as $part) {
            $out .= self::capitalize($part);
        }
        return $out;
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
        if (is_array($fields)) {
            return array_values($fields);
        }
        if ($fields instanceof \Traversable) {
            return array_values(iterator_to_array($fields));
        }
        return [];
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
        if ($value instanceof \Traversable && !is_object($value)) {
            return 'list';
        }
        if (is_object($value)) {
            if (method_exists($value, 'getKindCase')) {
                return self::valueKindFromProtoValueKind($value->getKindCase());
            }
            if (method_exists($value, 'WhichOneof')) {
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
            if (method_exists($value, 'hasNullValue') && $value->hasNullValue()) {
                return 'null';
            }
            if (method_exists($value, 'hasBoolValue') && $value->hasBoolValue()) {
                return 'bool';
            }
            if (method_exists($value, 'hasNumberValue') && $value->hasNumberValue()) {
                return 'number';
            }
            if (method_exists($value, 'hasStringValue') && $value->hasStringValue()) {
                return 'string';
            }
            if (method_exists($value, 'hasListValue') && $value->hasListValue()) {
                return 'list';
            }
            if (self::hasNullValue($value)) {
                return 'null';
            }
        }
        return 'missing';
    }

    private static function valueKindFromProtoValueKind(mixed $kindCase): string
    {
        $name = is_int($kindCase) ? (string) $kindCase : (string) $kindCase;
        return match (true) {
            str_contains($name, 'NULL') => 'null',
            str_contains($name, 'BOOL') => 'bool',
            str_contains($name, 'NUMBER') => 'number',
            str_contains($name, 'STRING') => 'string',
            str_contains($name, 'LIST') => 'list',
            default => 'missing',
        };
    }

    private static function hasNullValue(object $value): bool
    {
        if (method_exists($value, 'hasNullValue')) {
            return $value->hasNullValue();
        }
        return false;
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
        $val = self::get($value, 'bool_value', 'boolValue');
        return (bool) $val;
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
            if (is_object($lv)) {
                $vals = self::get($lv, 'values', 'Values');
                if (is_array($vals)) {
                    return array_values($vals);
                }
                if ($vals instanceof \Traversable) {
                    return array_values(iterator_to_array($vals));
                }
            }
        }
        if (is_object($value) && method_exists($value, 'getValues')) {
            return array_values(iterator_to_array($value->getValues()));
        }
        if (is_object($value) && method_exists($value, 'getValuesList')) {
            return array_values($value->getValuesList());
        }
        $lv = self::get($value, 'list_value', 'listValue');
        if ($lv !== null) {
            $vals = self::get($lv, 'values', 'Values');
            if (is_array($vals)) {
                return array_values($vals);
            }
            if ($vals instanceof \Traversable) {
                return array_values(iterator_to_array($vals));
            }
        }
        return [];
    }
}
