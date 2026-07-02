<?php

declare(strict_types=1);

namespace Apstndb\SpanValue\Tests;

use Apstndb\SpanValue\Encoder;
use Apstndb\SpanValue\Proto;
use Apstndb\SpanValue\TypeFormat;
use Apstndb\SpanValue\ValueFormat;
use PHPUnit\Framework\TestCase;

/** Minimal protobuf duck-types without google/cloud-spanner dependency. */
final class ProtoReflectionTest extends TestCase
{
    public function testTypeGetterReflection(): void
    {
        $type = new MockSpannerType(
            code: 10,
            arrayElementType: null,
            structType: new MockStructType([
                new MockStructField('n', new MockSpannerType(code: 2)),
            ]),
            protoTypeFqn: '',
            typeAnnotation: 0,
        );

        self::assertSame(10, Proto::typeCode($type));
        self::assertSame('STRUCT', TypeFormat::formatTypeSimple($type));

        $fields = Proto::structFields($type);
        self::assertCount(1, $fields);
        self::assertSame('n', Proto::fieldName($fields[0]));
        self::assertSame(2, Proto::typeCode(Proto::fieldType($fields[0])));
    }

    public function testValueGetterReflection(): void
    {
        $value = new MockProtoValue(
            kind: 'list',
            listValue: new MockListValue([
                new MockProtoValue(kind: 'string', stringValue: '1'),
                new MockProtoValue(kind: 'null'),
            ])
        );

        self::assertSame('list', Proto::valueKind($value));
        $vals = Proto::listValues($value);
        self::assertCount(2, $vals);
        self::assertSame('1', Proto::stringValue($vals[0]));
        self::assertTrue(Proto::isNullValue($vals[1]));
    }

    public function testFormatValueWithProtobufObjects(): void
    {
        $type = new MockSpannerType(
            code: 9,
            arrayElementType: new MockSpannerType(code: 2),
        );
        $value = new MockProtoValue(
            kind: 'list',
            listValue: new MockListValue([
                new MockProtoValue(kind: 'string', stringValue: '1'),
                new MockProtoValue(kind: 'string', stringValue: '2'),
            ])
        );

        $config = ValueFormat::simpleFormatConfig();
        self::assertSame('[1, 2]', ValueFormat::formatValue($type, $value, $config));
    }

    public function testEncodeValueWithProtobufType(): void
    {
        $type = new MockSpannerType(code: 2);
        $encoded = Encoder::encodeValue($type, 99);
        self::assertSame('99', $encoded);
    }
}

final class MockSpannerType
{
    public function __construct(
        private int $code,
        private ?MockSpannerType $arrayElementType = null,
        private ?MockStructType $structType = null,
        private string $protoTypeFqn = '',
        private int $typeAnnotation = 0,
    ) {
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function hasArrayElementType(): bool
    {
        return $this->arrayElementType !== null;
    }

    public function getArrayElementType(): ?MockSpannerType
    {
        return $this->arrayElementType;
    }

    public function hasStructType(): bool
    {
        return $this->structType !== null;
    }

    public function getStructType(): ?MockStructType
    {
        return $this->structType;
    }

    public function getProtoTypeFqn(): string
    {
        return $this->protoTypeFqn;
    }

    public function getTypeAnnotation(): int
    {
        return $this->typeAnnotation;
    }
}

final class MockStructType
{
    /** @param list<MockStructField> $fields */
    public function __construct(private array $fields)
    {
    }

    /** @return list<MockStructField> */
    public function getFields(): array
    {
        return $this->fields;
    }
}

final class MockStructField
{
    public function __construct(
        private string $name,
        private MockSpannerType $type,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasType(): bool
    {
        return true;
    }

    public function getType(): MockSpannerType
    {
        return $this->type;
    }
}

final class MockListValue
{
    /** @param list<MockProtoValue> $values */
    public function __construct(private array $values)
    {
    }

    /** @return list<MockProtoValue> */
    public function getValues(): array
    {
        return $this->values;
    }
}

final class MockProtoValue
{
    public function __construct(
        private string $kind,
        private bool $boolValue = false,
        private float $numberValue = 0.0,
        private string $stringValue = '',
        private ?MockListValue $listValue = null,
    ) {
    }

    public function hasNullValue(): bool
    {
        return $this->kind === 'null';
    }

    public function getNullValue(): int
    {
        return 0;
    }

    public function hasBoolValue(): bool
    {
        return $this->kind === 'bool';
    }

    public function getBoolValue(): bool
    {
        return $this->boolValue;
    }

    public function hasNumberValue(): bool
    {
        return $this->kind === 'number';
    }

    public function getNumberValue(): float
    {
        return $this->numberValue;
    }

    public function hasStringValue(): bool
    {
        return $this->kind === 'string';
    }

    public function getStringValue(): string
    {
        return $this->stringValue;
    }

    public function hasListValue(): bool
    {
        return $this->kind === 'list';
    }

    public function getListValue(): ?MockListValue
    {
        return $this->listValue;
    }
}
