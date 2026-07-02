# PHP Spanner emulator example

Runs `query_format.php` against the [Cloud Spanner emulator](https://cloud.google.com/spanner/docs/emulator) using [`google/cloud-spanner`](https://github.com/googleapis/google-cloud-php-spanner) and the local [`apstndb/spanvalue`](../../php) package (path repository).

## Prerequisites

1. Emulator running on `localhost:9010` (see [`../README.md`](../README.md)).
2. One-time bootstrap: `SPANNER_EMULATOR_HOST=localhost:9010 ../setup-emulator.sh`
3. PHP **8.1+** with **`ext-grpc`** and **`ext-intl`**.

`google/cloud-spanner` talks to the emulator over gRPC; Composer will refuse to install without `ext-grpc`.

### macOS (Homebrew PHP)

```bash
brew install php grpc
pecl install grpc
```

Enable the extension (adjust the PHP version directory if needed):

```bash
PHP_INI="$(php --ini | awk -F': ' '/Loaded Configuration File/ {print $2}')"
echo 'extension="grpc.so"' >> "$PHP_INI"
```

Verify:

```bash
php -m | grep -E 'grpc|intl'
```

If `pecl install grpc` fails to compile, ensure Xcode command-line tools are installed (`xcode-select --install`) and that `pkg-config` can find gRPC (`brew install grpc`). On Apple Silicon, Homebrew’s `php` and `grpc` formulae are the usual combination.

`ext-intl` is included in the default Homebrew `php` formula.

### Other platforms

Install `grpc` via your OS package manager or `pecl install grpc`, then enable `extension=grpc` in `php.ini`. See [PECL grpc](https://pecl.php.net/package/grpc).

## Run

```bash
cd examples/php
composer install
SPANNER_EMULATOR_HOST=localhost:9010 php query_format.php
```

Expected stdout:

```
encode_value (n): "1"
format_result_row: ["1","hello","true"]
```
