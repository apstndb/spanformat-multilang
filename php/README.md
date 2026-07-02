# spanvalue (PHP)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

PHP 8.1+ with `ext-intl`. No hard dependency on `google/cloud-spanner`.

## Input model

**Protojson arrays** are the primary, conformance-tested input path.

The library also duck-types objects with public properties or a `get($name)`
method, including `Google\Cloud\Spanner\V1` protobuf messages when available.
Protobuf support is thinner than the Java port — prefer protojson arrays for
portable integration.

High-level Spanner client row types are not accepted; convert to wire
`(Type, Value)` first.

## Quick start

```php
<?php
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
```

## Tests

```bash
cd php && composer install && vendor/bin/phpunit
```

Conformance cases load `../testdata/conformance.json`.

## License

MIT
