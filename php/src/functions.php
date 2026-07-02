<?php

declare(strict_types=1);

/**
 * Format Cloud Spanner types and column values.
 */

namespace Apstndb\SpanValue;

// Re-export public API with idiomatic function names.

function format_type(mixed $typ, ?FormatOption $option = null): string
{
    return TypeFormat::formatType($typ, $option);
}

function format_type_simplest(mixed $typ): string
{
    return TypeFormat::formatTypeSimplest($typ);
}

function format_type_simple(mixed $typ): string
{
    return TypeFormat::formatTypeSimple($typ);
}

function format_type_normal(mixed $typ): string
{
    return TypeFormat::formatTypeNormal($typ);
}

function format_type_verbose(mixed $typ): string
{
    return TypeFormat::formatTypeVerbose($typ);
}

function format_type_more_verbose(mixed $typ): string
{
    return TypeFormat::formatTypeMoreVerbose($typ);
}

function format_type_verbose_annotation_omit(mixed $typ): string
{
    return TypeFormat::formatTypeVerboseAnnotationOmit($typ);
}

function format_type_verbose_annotation_primary(mixed $typ): string
{
    return TypeFormat::formatTypeVerboseAnnotationPrimary($typ);
}

function simple_format_config(string $nullString = '<null>'): FormatConfig
{
    return ValueFormat::simpleFormatConfig($nullString);
}

function literal_format_config(
    ?LiteralQuoteConfig $quote = null,
    string $nullString = 'NULL',
): FormatConfig {
    return ValueFormat::literalFormatConfig($quote, $nullString);
}

function spanner_cli_format_config(string $nullString = 'NULL'): FormatConfig
{
    return ValueFormat::spannerCliFormatConfig($nullString);
}

function format_value(mixed $typ, mixed $value, FormatConfig $config, bool $toplevel = true): string
{
    return ValueFormat::formatValue($typ, $value, $config, $toplevel);
}

/** @param list<mixed> $types @param list<mixed> $values @return list<string> */
function format_row(array $types, array $values, FormatConfig $config): array
{
    return ValueFormat::formatRow($types, $values, $config);
}

const VERSION = '0.1.0-alpha.0';
