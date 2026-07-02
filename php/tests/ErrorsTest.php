<?php

declare(strict_types=1);

namespace Apstndb\SpanValue\Tests;

use Apstndb\SpanValue\EmptyNullStringError;
use Apstndb\SpanValue\EmptyTypeFQNError;
use Apstndb\SpanValue\FormatOption;
use Apstndb\SpanValue\MalformedWireError;
use Apstndb\SpanValue\MismatchedFieldsError;
use Apstndb\SpanValue\TypeFormat;
use Apstndb\SpanValue\UnexpectedComplexValueKindError;
use Apstndb\SpanValue\UnknownMode;
use Apstndb\SpanValue\UnknownTypeError;
use Apstndb\SpanValue\ValueFormat;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function testEmptyNullStringRejected(): void
    {
        $this->expectException(EmptyNullStringError::class);
        ValueFormat::simpleFormatConfig('');
    }

    public function testUnknownTypeCodeValue(): void
    {
        $this->expectException(UnknownTypeError::class);
        ValueFormat::formatValue(['code' => 999], true, ValueFormat::simpleFormatConfig());
    }

    public function testUnknownTypeCodeFormatPanic(): void
    {
        $this->expectException(UnknownTypeError::class);
        TypeFormat::formatType(['code' => 999], new FormatOption(unknown: UnknownMode::PANIC));
    }

    public function testMismatchedStructFields(): void
    {
        $typ = [
            'code' => 'STRUCT',
            'structType' => [
                'fields' => [
                    ['name' => 'n', 'type' => ['code' => 'INT64']],
                    ['name' => 's', 'type' => ['code' => 'STRING']],
                ],
            ],
        ];
        $this->expectException(MismatchedFieldsError::class);
        ValueFormat::formatValue($typ, ['1'], ValueFormat::simpleFormatConfig());
    }

    public function testMalformedBoolWire(): void
    {
        $this->expectException(MalformedWireError::class);
        ValueFormat::formatValue(['code' => 'BOOL'], 'true', ValueFormat::simpleFormatConfig());
    }

    public function testMalformedInt64Wire(): void
    {
        $this->expectException(MalformedWireError::class);
        ValueFormat::formatValue(['code' => 'INT64'], 'not-a-number', ValueFormat::literalFormatConfig());
    }

    public function testMalformedFloatWire(): void
    {
        $this->expectException(MalformedWireError::class);
        ValueFormat::formatValue(['code' => 'FLOAT64'], '3.14', ValueFormat::simpleFormatConfig());
    }

    public function testEmptyProtoFqnLiteral(): void
    {
        $this->expectException(EmptyTypeFQNError::class);
        ValueFormat::formatValue(['code' => 'PROTO'], 'YWJj', ValueFormat::literalFormatConfig());
    }

    public function testEmptyEnumFqnLiteral(): void
    {
        $this->expectException(EmptyTypeFQNError::class);
        ValueFormat::formatValue(['code' => 'ENUM'], '1', ValueFormat::literalFormatConfig());
    }

    public function testFormatRowLengthMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ValueFormat::formatRow([['code' => 'INT64']], ['1', '2'], ValueFormat::simpleFormatConfig());
    }

    public function testNullRendersAtAnyDepth(): void
    {
        $typ = [
            'code' => 'ARRAY',
            'arrayElementType' => ['code' => 'INT64'],
        ];
        $got = ValueFormat::formatValue($typ, [null], ValueFormat::simpleFormatConfig());
        self::assertSame('[<null>]', $got);
    }

    public function testTypeUnspecifiedValueError(): void
    {
        $this->expectException(UnknownTypeError::class);
        ValueFormat::formatValue([], true, ValueFormat::simpleFormatConfig());
    }

    public function testArrayNonListWire(): void
    {
        $typ = ['code' => 'ARRAY', 'arrayElementType' => ['code' => 'INT64']];
        $this->expectException(UnexpectedComplexValueKindError::class);
        ValueFormat::formatValue($typ, '1', ValueFormat::simpleFormatConfig());
    }

    public function testNanFloatWireString(): void
    {
        $got = ValueFormat::formatValue(['code' => 'FLOAT64'], 'NaN', ValueFormat::simpleFormatConfig());
        self::assertSame('NaN', $got);
    }

    public function testInfFloatWireString(): void
    {
        $got = ValueFormat::formatValue(['code' => 'FLOAT64'], 'Infinity', ValueFormat::simpleFormatConfig());
        self::assertSame('+Inf', $got);
    }

    public function testNegativeInfFloatWireString(): void
    {
        $got = ValueFormat::formatValue(['code' => 'FLOAT64'], '-Infinity', ValueFormat::simpleFormatConfig());
        self::assertSame('-Inf', $got);
    }

    public function testNumberWireFiniteFloat(): void
    {
        $got = ValueFormat::formatValue(['code' => 'FLOAT64'], M_PI, ValueFormat::simpleFormatConfig());
        self::assertSame('3.141592653589793', $got);
    }
}
