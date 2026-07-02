<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

enum StructMode: int
{
    case BASE = 0;
    case RECURSIVE = 1;
    case RECURSIVE_WITH_NAME = 2;
}

enum ProtoEnumMode: int
{
    case BASE = 0;
    case LEAF = 1;
    case FULL = 2;
    case LEAF_WITH_KIND = 3;
    case FULL_WITH_KIND = 4;
}

enum ArrayMode: int
{
    case BASE = 0;
    case RECURSIVE = 1;
}

enum UnknownMode: int
{
    case UNKNOWN = 0;
    case TYPE_CODE = 1;
    case VERBOSE = 2;
    case PANIC = 3;
}

enum TypeAnnotationMode: int
{
    case SUFFIX = 0;
    case OMIT = 1;
    case PRIMARY = 2;
}

final readonly class FormatOption
{
    public function __construct(
        public StructMode $struct = StructMode::BASE,
        public ProtoEnumMode $proto = ProtoEnumMode::BASE,
        public ProtoEnumMode $enum = ProtoEnumMode::BASE,
        public ArrayMode $array = ArrayMode::BASE,
        public UnknownMode $unknown = UnknownMode::UNKNOWN,
        public TypeAnnotationMode $typeAnnotation = TypeAnnotationMode::SUFFIX,
    ) {
    }

    public function withTypeAnnotation(TypeAnnotationMode $mode): self
    {
        return new self(
            $this->struct,
            $this->proto,
            $this->enum,
            $this->array,
            $this->unknown,
            $mode,
        );
    }
}

final class TypeFormat
{
    public static FormatOption $optionSimplest;
    public static FormatOption $optionSimple;
    public static FormatOption $optionNormal;
    public static FormatOption $optionVerbose;
    public static FormatOption $optionMoreVerbose;

    public static function init(): void
    {
        self::$optionSimplest = new FormatOption(
            unknown: UnknownMode::TYPE_CODE,
        );
        self::$optionSimple = new FormatOption(
            proto: ProtoEnumMode::LEAF,
            enum: ProtoEnumMode::LEAF,
            array: ArrayMode::RECURSIVE,
        );
        self::$optionNormal = new FormatOption(
            struct: StructMode::RECURSIVE,
            proto: ProtoEnumMode::LEAF,
            enum: ProtoEnumMode::LEAF,
            array: ArrayMode::RECURSIVE,
            unknown: UnknownMode::VERBOSE,
        );
        self::$optionVerbose = new FormatOption(
            struct: StructMode::RECURSIVE_WITH_NAME,
            proto: ProtoEnumMode::FULL,
            enum: ProtoEnumMode::FULL,
            array: ArrayMode::RECURSIVE,
            unknown: UnknownMode::VERBOSE,
        );
        self::$optionMoreVerbose = new FormatOption(
            struct: StructMode::RECURSIVE_WITH_NAME,
            proto: ProtoEnumMode::FULL_WITH_KIND,
            enum: ProtoEnumMode::FULL_WITH_KIND,
            array: ArrayMode::RECURSIVE,
            unknown: UnknownMode::VERBOSE,
        );
    }

    private static function lastCut(string $s, string $sep): string
    {
        $pos = strrpos($s, $sep);
        if ($pos === false) {
            return $s;
        }
        return substr($s, $pos + strlen($sep));
    }

    private static function annotationSuffix(int $ann): string
    {
        if ($ann === TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED->value) {
            return '';
        }
        $name = TypeAnnotationCode::nameOf($ann);
        if ($name === null) {
            return "({$ann})";
        }
        return "({$name})";
    }

    private static function annotationName(int $ann): string
    {
        $name = TypeAnnotationCode::nameOf($ann);
        return $name ?? (string) $ann;
    }

    public static function formatTypeCode(int $code, UnknownMode $mode = UnknownMode::VERBOSE): string
    {
        $name = TypeCode::nameOf($code);
        if ($name !== null) {
            return $name;
        }
        return match ($mode) {
            UnknownMode::TYPE_CODE => (string) $code,
            UnknownMode::VERBOSE => "UNKNOWN({$code})",
            UnknownMode::PANIC => throw new UnknownTypeError("unknown TypeCode({$code})"),
            default => 'UNKNOWN',
        };
    }

    public static function formatProtoEnum(mixed $typ, ProtoEnumMode $mode): string
    {
        $code = Proto::typeCode($typ);
        $fqn = Proto::protoTypeFqn($typ);
        $codeName = self::formatTypeCode($code);
        return match ($mode) {
            ProtoEnumMode::LEAF => self::lastCut($fqn, '.'),
            ProtoEnumMode::FULL => $fqn,
            ProtoEnumMode::LEAF_WITH_KIND => "{$codeName}<" . self::lastCut($fqn, '.') . '>',
            ProtoEnumMode::FULL_WITH_KIND => "{$codeName}<{$fqn}>",
            default => $codeName,
        };
    }

    /** @param list<mixed> $fields */
    public static function formatStructFields(array $fields, FormatOption $option): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $typeStr = self::formatType(Proto::fieldType($field), $option);
            if ($option->struct === StructMode::RECURSIVE_WITH_NAME && Proto::fieldName($field) !== '') {
                $parts[] = Proto::fieldName($field) . ' ' . $typeStr;
            } else {
                $parts[] = $typeStr;
            }
        }
        return implode(', ', $parts);
    }

    private static function formatTypeImpl(mixed $typ, FormatOption $option): string
    {
        $code = Proto::typeCode($typ);
        if ($code === TypeCode::ARRAY->value && $option->array !== ArrayMode::BASE) {
            $elem = Proto::arrayElementType($typ);
            return 'ARRAY<' . self::formatType($elem, $option) . '>';
        }
        if ($code === TypeCode::PROTO->value) {
            return self::formatProtoEnum($typ, $option->proto);
        }
        if ($code === TypeCode::ENUM->value) {
            return self::formatProtoEnum($typ, $option->enum);
        }
        if ($code === TypeCode::STRUCT->value && $option->struct !== StructMode::BASE) {
            return 'STRUCT<' . self::formatStructFields(Proto::structFields($typ), $option) . '>';
        }
        return self::formatTypeCode($code, $option->unknown);
    }

    public static function formatType(mixed $typ, ?FormatOption $option = null): string
    {
        $option ??= self::$optionSimple;
        $ann = Proto::typeAnnotation($typ);
        if ($option->typeAnnotation === TypeAnnotationMode::OMIT) {
            return self::formatTypeImpl($typ, $option);
        }
        if ($option->typeAnnotation === TypeAnnotationMode::PRIMARY) {
            if ($ann !== TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED->value) {
                return self::annotationName($ann);
            }
            return self::formatTypeImpl($typ, $option);
        }
        return self::formatTypeImpl($typ, $option) . self::annotationSuffix($ann);
    }

    public static function formatTypeSimplest(mixed $typ): string
    {
        return self::formatType($typ, self::$optionSimplest);
    }

    public static function formatTypeSimple(mixed $typ): string
    {
        return self::formatType($typ, self::$optionSimple);
    }

    public static function formatTypeNormal(mixed $typ): string
    {
        return self::formatType($typ, self::$optionNormal);
    }

    public static function formatTypeVerbose(mixed $typ): string
    {
        return self::formatType($typ, self::$optionVerbose);
    }

    public static function formatTypeMoreVerbose(mixed $typ): string
    {
        return self::formatType($typ, self::$optionMoreVerbose);
    }

    public static function formatTypeVerboseAnnotationOmit(mixed $typ): string
    {
        return self::formatType($typ, self::$optionVerbose->withTypeAnnotation(TypeAnnotationMode::OMIT));
    }

    public static function formatTypeVerboseAnnotationPrimary(mixed $typ): string
    {
        return self::formatType($typ, self::$optionVerbose->withTypeAnnotation(TypeAnnotationMode::PRIMARY));
    }
}

TypeFormat::init();
