<?php

declare(strict_types=1);

namespace Apstndb\SpanValue\Tests;

use Apstndb\SpanValue\Encoder;
use Apstndb\SpanValue\ValueFormat;
use PHPUnit\Framework\TestCase;

final class EncoderTest extends TestCase
{
    private static ?array $conformance = null;

    public static function setUpBeforeClass(): void
    {
        self::$conformance = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/testdata/conformance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private static function conformance(): array
    {
        return self::$conformance ?? throw new \RuntimeException('conformance data not loaded');
    }

    public function testEncodeRoundTripFromConformance(): void
    {
        foreach (self::conformance()['value_cases'] as $case) {
            $type = $case['type'];
            $wire = $case['value'];
            $native = Encoder::wireToNative($type, $wire);
            $encoded = Encoder::encodeValue($type, $native);
            self::assertTrue(
                Encoder::wireEqual($type, $encoded, $wire),
                "encode round-trip {$case['name']}: encoded "
                . var_export($encoded, true)
                . ' want ' . var_export($wire, true)
            );
        }
    }

    /** @return list<array{0: string}> */
    public static function valuePresetProvider(): array
    {
        return array_map(static fn (string $p) => [$p], ['simple', 'literal', 'spanner_cli']);
    }

    /** @dataProvider valuePresetProvider */
    public function testEncodeThenFormatMatchesConformance(string $preset): void
    {
        $config = match ($preset) {
            'simple' => ValueFormat::simpleFormatConfig(),
            'literal' => ValueFormat::literalFormatConfig(),
            'spanner_cli' => ValueFormat::spannerCliFormatConfig(),
            default => throw new \InvalidArgumentException("unknown preset {$preset}"),
        };

        foreach (self::conformance()['value_cases'] as $case) {
            $type = $case['type'];
            $native = Encoder::wireToNative($type, $case['value']);
            $encoded = Encoder::encodeValue($type, $native);
            $got = ValueFormat::formatValue($type, $encoded, $config);
            $want = $case['expected'][$preset];
            self::assertSame(
                $want,
                $got,
                "encode+format {$case['name']} preset {$preset}: got " . var_export($got, true)
                . ' want ' . var_export($want, true)
            );
        }
    }

    public function testFormatResultRow(): void
    {
        $types = [
            ['code' => 'INT64'],
            [
                'code' => 'STRUCT',
                'structType' => [
                    'fields' => [
                        ['name' => 'n', 'type' => ['code' => 'INT64']],
                        ['name' => 's', 'type' => ['code' => 'STRING']],
                    ],
                ],
            ],
            ['code' => 'STRING'],
        ];
        $nativeValues = [42, ['n' => 1, 's' => 'hello'], null];
        $config = ValueFormat::simpleFormatConfig();

        $got = Encoder::formatResultRow($types, $nativeValues, $config);
        self::assertSame(['42', '(1 AS n, hello AS s)', '<null>'], $got);
    }

    public function testStructAssociativeEncoding(): void
    {
        $type = [
            'code' => 'STRUCT',
            'structType' => [
                'fields' => [
                    ['name' => 'a', 'type' => ['code' => 'INT64']],
                    ['name' => 'b', 'type' => ['code' => 'BOOL']],
                ],
            ],
        ];
        $encoded = Encoder::encodeValue($type, ['a' => '7', 'b' => true]);
        self::assertSame(['7', true], $encoded);
    }

    public function testBytesFromRawBinary(): void
    {
        $type = ['code' => 'BYTES'];
        $encoded = Encoder::encodeValue($type, "\x00ok");
        self::assertSame('AG9r', $encoded);
    }

    public function testJsonFromArray(): void
    {
        $type = ['code' => 'JSON'];
        $encoded = Encoder::encodeValue($type, ['x' => 1]);
        self::assertSame('{"x":1}', $encoded);
    }
}
