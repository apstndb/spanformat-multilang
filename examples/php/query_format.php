#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run a literal SELECT on the Spanner emulator and format cells with spanvalue.
 */

use Apstndb\SpanValue\ClientTypeAdapter;
use function Apstndb\SpanValue\encode_value;
use function Apstndb\SpanValue\format_result_row;
use function Apstndb\SpanValue\simple_format_config;
use Google\Cloud\Spanner\SpannerClient;

require __DIR__ . '/vendor/autoload.php';

$_SERVER['SPANNER_EMULATOR_HOST'] ??= 'localhost:9010';
putenv('SPANNER_EMULATOR_HOST=' . $_SERVER['SPANNER_EMULATOR_HOST']);

$sql = "SELECT 1 AS n, 'hello' AS s, true AS b";
$projectId = getenv('SPANNER_PROJECT_ID') ?: 'test-project';
$instanceId = getenv('SPANNER_INSTANCE_ID') ?: 'test-instance';
$databaseId = getenv('SPANNER_DATABASE_ID') ?: 'test-db';

$client = new SpannerClient(['projectId' => $projectId]);
$database = $client->instance($instanceId)->database($databaseId);
$config = simple_format_config();

$result = $database->execute($sql);

// Metadata is populated after the result set is iterated (same timing as other clients).
$rows = iterator_to_array($result->rows());
if ($rows === []) {
    fwrite(STDERR, "Query returned no rows.\n");
    exit(1);
}

$fields = $result->metadata()['rowType']['fields'] ?? [];
$colTypes = array_map(static fn (array $field): array => ClientTypeAdapter::adapt($field['type']), $fields);

foreach ($rows as $row) {
    $nativeValues = array_map(static fn (array $field) => $row[$field['name']], $fields);

    $wireValue = encode_value($colTypes[0], $nativeValues[0]);
    echo 'encode_value (n): ' . json_encode($wireValue, JSON_THROW_ON_ERROR) . PHP_EOL;

    $formatted = format_result_row($colTypes, $nativeValues, $config);
    echo 'format_result_row: ' . json_encode($formatted, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
