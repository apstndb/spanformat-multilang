# Spanner client integration examples

Runnable demos that connect to the [Cloud Spanner emulator](https://cloud.google.com/spanner/docs/emulator), execute a literal SQL query, and format result cells with each language port's encoder APIs:

```
client row values + column types from result metadata
  → adapt_client_type (where applicable)
  → encode_value
  → format_result_row
  → stdout
```

Query used by every example:

```sql
SELECT 1 AS n, 'hello' AS s, true AS b
```

## Prerequisites

### 1. Start the emulator

**gcloud** (included in [Google Cloud SDK](https://cloud.google.com/sdk)):

```bash
gcloud emulators spanner start
```

**Docker**:

```bash
docker run -p 9010:9010 -p 9020:9020 gcr.io/cloud-spanner-emulator/emulator
```

### 2. Bootstrap instance and database (one time)

With the emulator running:

```bash
export SPANNER_EMULATOR_HOST=localhost:9010
./examples/setup-emulator.sh
```

Defaults (override with env vars):

| Variable | Default |
|---|---|
| `SPANNER_EMULATOR_HOST` | `localhost:9010` |
| `SPANNER_PROJECT_ID` | `test-project` |
| `SPANNER_INSTANCE_ID` | `test-instance` |
| `SPANNER_DATABASE_ID` | `test-db` |

No GCP credentials are required when `SPANNER_EMULATOR_HOST` is set.

## Run (one-liner per language)

| Language | Command |
|---|---|
| Python | `cd examples/python && pip install -r requirements.txt && SPANNER_EMULATOR_HOST=localhost:9010 python query_format.py` |
| Java | `cd java && mvn -q install -DskipTests && cd ../examples/java && mvn -q -DskipTests package exec:java` |
| Node.js | `cd examples/nodejs && npm install && SPANNER_EMULATOR_HOST=localhost:9010 node query_format.mjs` |
| Ruby | `cd examples/ruby && bundle install && SPANNER_EMULATOR_HOST=localhost:9010 bundle exec ruby query_format.rb` |
| PHP | `cd examples/php && composer install && SPANNER_EMULATOR_HOST=localhost:9010 php query_format.php` |
| C# | `cd examples/csharp && dotnet run` |
| Rust | `cd examples/rust && SPANNER_EMULATOR_HOST=localhost:9010 cargo run` |
| C++ | `cd examples/cpp && cmake -B build && cmake --build build && SPANNER_EMULATOR_HOST=localhost:9010 ./build/query_format` |

Expected output (similar across languages):

```
encode_value (n): ...
format_result_row: ["1", "hello", "true"]
```

## Per-language notes

- **Python / Java / Node / Ruby / PHP / C# / Rust** — column types come from official client result metadata.
- **C++** — the high-level `RowStream` does not expose `ResultSetMetadata`; the example documents wire types equivalent to metadata for this fixed `SELECT` and still runs the query through `google::cloud::spanner::Client`.
- **C++** additionally requires [google-cloud-cpp](https://github.com/googleapis/google-cloud-cpp) with the Spanner component installed (`find_package(google_cloud_cpp_spanner)`).

## CI

Core library conformance tests run in CI on every push. These emulator examples are **manual** — they are not wired into `.github/workflows/ci.yml` because they need a running Spanner emulator and per-language client dependencies. They are intended as integration documentation and local verification.

## Layout

```
examples/
  setup-emulator.sh
  python/query_format.py
  java/...
  nodejs/query_format.mjs
  ruby/query_format.rb
  php/query_format.php
  csharp/Program.cs
  rust/src/main.rs
  cpp/query_format.cpp
```

Each example directory is self-contained with its own client dependency manifest (`requirements.txt`, `pom.xml`, `package.json`, etc.). None of these dependencies are added to the zero-dep core libraries.
