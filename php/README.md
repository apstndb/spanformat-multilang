# spanvalue (PHP)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

PHP 8.1+ with `ext-intl`. No hard dependency on `google/cloud-spanner`.

## Input model

**Protojson arrays** are the primary, conformance-tested input path.

The library also duck-types objects with public properties, protobuf getters
(`getCode()`, `getListValue()`, …), or a `get($name)` method, including
`Google\Cloud\Spanner\V1` and `Google\Protobuf\Value` messages when available.
Protobuf support is best-effort; prefer protojson arrays for portable integration.

High-level Spanner client row types are not accepted; convert to wire
`(Type, Value)` first.

## Quick start

```php
<?php
use function Apstndb\SpanValue\encode_value;
use function Apstndb\SpanValue\format_result_row;
use function Apstndb\SpanValue\format_value;
use function Apstndb\SpanValue\literal_format_config;

$typ = [
    'code' => 'STRUCT',
    'structType' => [
        'fields' => [
            ['name' => 'n', 'type' => ['code' => 'INT64']],
            ['name' => 's', 'type' => ['code' => 'STRING']],
        ],
    ],
];
$value = ['1', 'hello'];

$config = literal_format_config();
echo format_value($typ, $value, $config);
// STRUCT<n INT64, s STRING>(1, "hello")

// Build wire values from native PHP data, then format a result row:
$wire = encode_value(['code' => 'INT64'], 42);
$cells = format_result_row(
    [['code' => 'INT64'], $typ],
    [42, ['n' => 1, 's' => 'hello']],
    $config,
);
```

## Encoder API

- `encode_value($type, $nativeValue)` — build a wire `google.protobuf.Value`
  (protojson-compatible array/scalar) from native PHP data.
- `format_result_row($types, $nativeValues, $config)` — `encode_value` per column,
  then `format_row`.

Native inputs: scalars, `null`, ordered lists for `ARRAY`/`STRUCT`, or associative
arrays for `STRUCT` keyed by field name. `BYTES`/`PROTO` accept raw binary strings
(base64-encoded on the wire) or existing base64 wire text.

Protobuf duck-typing: `Google\Cloud\Spanner\V1\Type` and `Google\Protobuf\Value`
objects are supported for formatting via getter reflection (`getCode()`,
`getListValue()`, etc.) in addition to protojson arrays.

## Tests

```bash
cd php && composer install && vendor/bin/phpunit
```

Conformance cases load `../testdata/conformance.json`.

## Integration example

See [`../examples/php/`](../examples/php/) for a runnable Spanner emulator demo (`query_format.php`) using `ClientTypeAdapter::adapt`, `encode_value`, and `format_result_row`. Setup: [`../examples/README.md`](../examples/README.md).

## License

MIT
