<?php

declare(strict_types=1);

namespace Apstndb\SpanValue\Tests;

use Apstndb\SpanValue\FormatOption;
use Apstndb\SpanValue\LiteralQuoteConfig;
use Apstndb\SpanValue\PreferredQuote;
use Apstndb\SpanValue\QuoteStrategy;
use Apstndb\SpanValue\TypeFormat;
use Apstndb\SpanValue\UnknownMode;
use Apstndb\SpanValue\ValueFormat;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
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

    private static function formatTypePreset(string $name, array $typ): string
    {
        return match ($name) {
            'verbose_annotation_omit' => TypeFormat::formatTypeVerboseAnnotationOmit($typ),
            'verbose_annotation_primary' => TypeFormat::formatTypeVerboseAnnotationPrimary($typ),
            'simplest' => TypeFormat::formatType($typ, TypeFormat::$optionSimplest),
            'simple' => TypeFormat::formatType($typ, TypeFormat::$optionSimple),
            'normal' => TypeFormat::formatType($typ, TypeFormat::$optionNormal),
            'verbose' => TypeFormat::formatType($typ, TypeFormat::$optionVerbose),
            'more_verbose' => TypeFormat::formatType($typ, TypeFormat::$optionMoreVerbose),
            default => throw new \InvalidArgumentException("unknown preset {$name}"),
        };
    }

    /** @return list<array{0: string}> */
    public static function typePresetProvider(): array
    {
        return array_map(
            static fn (string $p) => [$p],
            [
                'simplest', 'simple', 'normal', 'verbose', 'more_verbose',
                'verbose_annotation_omit', 'verbose_annotation_primary',
            ]
        );
    }

    /** @dataProvider typePresetProvider */
    public function testTypeCases(string $preset): void
    {
        foreach (self::conformance()['type_cases'] as $case) {
            $got = self::formatTypePreset($preset, $case['type']);
            $want = $case['expected'][$preset];
            self::assertSame(
                $want,
                $got,
                "type case {$case['name']} preset {$preset}: got " . var_export($got, true)
                . ' want ' . var_export($want, true)
            );
        }
    }

    /** @return list<array{0: string}> */
    public static function valuePresetProvider(): array
    {
        return array_map(static fn (string $p) => [$p], ['simple', 'literal', 'spanner_cli']);
    }

    /** @dataProvider valuePresetProvider */
    public function testValueCases(string $preset): void
    {
        $config = match ($preset) {
            'simple' => ValueFormat::simpleFormatConfig(),
            'literal' => ValueFormat::literalFormatConfig(),
            'spanner_cli' => ValueFormat::spannerCliFormatConfig(),
            default => throw new \InvalidArgumentException("unknown preset {$preset}"),
        };

        foreach (self::conformance()['value_cases'] as $case) {
            $got = ValueFormat::formatValue($case['type'], $case['value'], $config);
            $want = $case['expected'][$preset];
            self::assertSame(
                $want,
                $got,
                "value case {$case['name']} preset {$preset}: got " . var_export($got, true)
                . ' want ' . var_export($want, true)
            );
        }
    }

    private static function quotePolicy(string $name): LiteralQuoteConfig
    {
        return match ($name) {
            'legacy_double' => new LiteralQuoteConfig(QuoteStrategy::LEGACY, PreferredQuote::DOUBLE),
            'legacy_single' => new LiteralQuoteConfig(QuoteStrategy::LEGACY, PreferredQuote::SINGLE),
            'always_double' => new LiteralQuoteConfig(QuoteStrategy::ALWAYS, PreferredQuote::DOUBLE),
            'always_single' => new LiteralQuoteConfig(QuoteStrategy::ALWAYS, PreferredQuote::SINGLE),
            'min_escape_double' => new LiteralQuoteConfig(QuoteStrategy::MIN_ESCAPE, PreferredQuote::DOUBLE),
            'min_escape_single' => new LiteralQuoteConfig(QuoteStrategy::MIN_ESCAPE, PreferredQuote::SINGLE),
            default => throw new \InvalidArgumentException("unknown policy {$name}"),
        };
    }

    /** @return list<array{0: string}> */
    public static function quotePolicyProvider(): array
    {
        return array_map(
            static fn (string $p) => [$p],
            [
                'legacy_double', 'legacy_single', 'always_double', 'always_single',
                'min_escape_double', 'min_escape_single',
            ]
        );
    }

    /** @dataProvider quotePolicyProvider */
    public function testValueLiteralQuotes(string $policyName): void
    {
        $config = ValueFormat::literalFormatConfig(self::quotePolicy($policyName));
        foreach (self::conformance()['value_cases'] as $case) {
            $got = ValueFormat::formatValue($case['type'], $case['value'], $config);
            $want = $case['expected']['literal_quotes'][$policyName];
            self::assertSame(
                $want,
                $got,
                "value case {$case['name']} quote {$policyName}: got " . var_export($got, true)
                . ' want ' . var_export($want, true)
            );
        }
    }
}
