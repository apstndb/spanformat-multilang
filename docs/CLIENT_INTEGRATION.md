# 公式 Spanner クライアントとの統合ガイド

spanformat-multilang の各言語ポートは、**公式 Spanner クライアント**でクエリを実行し、結果行を人間が読める文字列に整形するためのものです。コア API は次の 3 段階です（[`spec/ENCODER.md`](../spec/ENCODER.md) 参照）。

```
列型（wire Type） + ネイティブ列値
  → adapt_client_type（必要な場合）
  → encode_value
  → format_result_row（または format_row）
```

**入力モデル**: 整形の正規形は Spanner **ワイヤ**表現（`google.spanner.v1.Type` + `google.protobuf.Value`）です。クライアントが返す `Long` / `string` / `Struct` などのネイティブ値は、`encode_value` でワイヤ `Value` に変換してから `format_value` します。

**エミュレータ**: 各言語の実行例は [`examples/`](../examples/) にあります。事前に `SPANNER_EMULATOR_HOST=localhost:9010` と `./examples/setup-emulator.sh` が必要です（詳細は [`examples/README.md`](../examples/README.md)）。

---

## 共通フロー（4 ステップ）

| ステップ | 内容 |
|---|---|
| **1. クエリ実行** | 各言語の公式クライアントで `SELECT` を実行 |
| **2. 列型の取得** | 結果メタデータの `row_type.fields[].type`（または同等 API） |
| **3. 行値の取得** | **ネイティブ** getter（`getLong` / `row[i]` / `json: true` など）。`getValue` のワイヤ protobuf はエンコーダ入力にしない |
| **4. spanformat 呼び出し** | `adapt_client_type` → `encode_value` / `format_result_row` |

### よくある落とし穴

- **メタデータのタイミング**: PHP など一部クライアントは、行イテレーション後に `metadata()` が埋まります。
- **ワイヤ Value vs ネイティブ**: Java の `ResultSet#getValue` は protobuf `Value` を返します。`encode_value` には `getLong` / `getString` 等のネイティブ値を渡してください。
- **STRUCT / ARRAY**: 列型は `adapt_client_type` で再帰的に変換が必要です。フィールド順はメタデータの `struct_type.fields` 順に合わせます。
- **C# エミュレータ**: `SPANNER_EMULATOR_HOST` だけでは不十分な場合があり、`EmulatorDetection = EmulatorOnly` と `EnableGetSchemaTable = true` が必要です。
- **C++ 高水準 API**: `RowStream` は `ResultSetMetadata` を公開しません（下記参照）。

---

## Python（`google-cloud-spanner`）

| 項目 | API |
|---|---|
| クエリ | `database.snapshot().execute_sql(sql)` |
| 列型 | `result_set.metadata.row_type.fields[].type_`（`google.spanner.v1.Type`） |
| 行値 | `list(row)` または `row[i]`（ネイティブ Python 型） |
| spanformat | `adapt_client_type`, `encode_value`, `format_result_row` |

`field.type_` はワイヤ protobuf ですが、`adapt_client_type` は `google.cloud.spanner_v1` 型と高水準 `google.cloud.spanner.Type` の両方に対応します。

```python
from spanvalue import adapt_client_type, encode_value, format_result_row, simple_format_config

with database.snapshot() as snapshot:
    result_set = snapshot.execute_sql(sql)
    rows = list(result_set)
    fields = result_set.metadata.row_type.fields
    col_types = [adapt_client_type(f.type_) for f in fields]
    native_values = list(rows[0])
    formatted = format_result_row(col_types, native_values, simple_format_config())
```

完全な例: [`examples/python/query_format.py`](../examples/python/query_format.py)

---

## Java（`google-cloud-spanner`）

| 項目 | API |
|---|---|
| クエリ | `db.singleUse().executeQuery(Statement.of(sql))` |
| 列型 | `ResultSet#getColumnType(i)` → `com.google.cloud.spanner.Type` |
| 行値 | `getLong` / `getString` / `getBoolean` 等（**`getValue` は使わない**） |
| spanformat | `SpanValue.adaptClientType`, `encodeValue`, `formatResultRow` |

`ClientTypeAdapter` はリフレクションで `Type#getStructFields()` をワイヤ `struct_type.fields` にマップします（`google-cloud-spanner` はコア JAR に含めません）。

```java
Type columnType = rs.getColumnType(i);
types.add(SpanValue.adaptClientType(columnType));
values.add(nativeValue(rs, i, columnType)); // getLong, getString, ...
List<String> formatted = SpanValue.formatResultRow(types, values, SpanValue.simpleFormatConfig());
```

完全な例: [`examples/java/.../QueryFormatExample.java`](../examples/java/src/main/java/com/github/apstndb/spanvalue/examples/QueryFormatExample.java)

---

## Node.js（`@google-cloud/spanner`）

| 項目 | API |
|---|---|
| クエリ | `database.run({ sql, json: true })` |
| 列型 | `response.rowType.fields[].type` |
| 行値 | `row[field.name]`（`json: true` でプレーン JS 値） |
| spanformat | `adaptClientType`, `encodeValue`, `formatResultRow` |

`json: true` を付けると行がオブジェクトになり、数値・真偽値がネイティブ型で取れます。型メタデータは `response.rowType` から取得します。

```javascript
const [rows, , response] = await database.run({ sql, json: true });
const fields = response.rowType.fields;
const colTypes = fields.map((f) => adaptClientType(f.type));
const nativeValues = fields.map((f) => rows[0][f.name]);
const formatted = formatResultRow(colTypes, nativeValues, simpleFormatConfig());
```

完全な例: [`examples/nodejs/query_format.mjs`](../examples/nodejs/query_format.mjs)

---

## Ruby（`google-cloud-spanner`）

| 項目 | API |
|---|---|
| クエリ | `client.execute(sql)` |
| 列型 | `result.metadata.row_type.fields[].type` |
| 行値 | `row[index]` |
| spanformat | `Spanvalue::ClientTypeAdapter.adapt`, `encode_value`, `format_result_row` |

```ruby
fields = result.metadata.row_type.fields
col_types = fields.map { |f| Spanvalue::ClientTypeAdapter.adapt(f.type) }
native_values = fields.map.with_index { |_f, i| row[i] }
formatted = Spanvalue.format_result_row(col_types, native_values, config)
```

完全な例: [`examples/ruby/query_format.rb`](../examples/ruby/query_format.rb)

---

## PHP（`google/cloud-spanner`）

| 項目 | API |
|---|---|
| クエリ | `$database->execute($sql)` |
| 列型 | `$result->metadata()['rowType']['fields'][].type`（**行イテレーション後**） |
| 行値 | `$row[$field['name']]` |
| spanformat | `ClientTypeAdapter::adapt`, `encode_value`, `format_result_row` |

メタデータの `type` は protojson 互換の配列です。`ClientTypeAdapter` がワイヤ形に正規化します。

```php
$rows = iterator_to_array($result->rows());
$fields = $result->metadata()['rowType']['fields'] ?? [];
$colTypes = array_map(fn ($f) => ClientTypeAdapter::adapt($f['type']), $fields);
$nativeValues = array_map(fn ($f) => $row[$f['name']], $fields);
$formatted = format_result_row($colTypes, $nativeValues, $config);
```

完全な例: [`examples/php/query_format.php`](../examples/php/query_format.php)（`ext-grpc` / `ext-intl` が必要 — [`examples/php/README.md`](../examples/php/README.md)）

---

## C#（`Google.Cloud.Spanner.Data`）

| 項目 | API |
|---|---|
| クエリ | `SpannerConnection` + `CreateSelectCommand` + `ExecuteReaderAsync` |
| 列型 | `reader.GetSchemaTable()` の `ProviderType` → `SpannerDbType` |
| 行値 | `GetInt64` / `GetString` / `GetBoolean` 等 |
| spanformat | `ValueEncoder.AdaptClientType`, `EncodeValue`, `FormatResultRow` |

```csharp
var builder = new SpannerConnectionStringBuilder {
    DataSource = $"projects/{projectId}/instances/{instanceId}/databases/{databaseId}",
    EmulatorDetection = EmulatorDetection.EmulatorOnly,
    EnableGetSchemaTable = true,
};
var spannerType = (SpannerDbType)schema.Rows[i]["ProviderType"];
types.Add(ValueEncoder.AdaptClientType(spannerType));
```

完全な例: [`examples/csharp/Program.cs`](../examples/csharp/Program.cs)

---

## Rust（`google-cloud-spanner` プレビュー）

| 項目 | API |
|---|---|
| クエリ | `transaction.execute_query(Statement::builder(sql).build())` |
| 列型 | `result_set.metadata().column_types()` |
| 行値 | `row.try_get("col")` / `try_is_null` |
| spanformat | `type_from_parts` / `type_from_protojson`, `encode_value`, `format_result_row` |

コア crate は `google-cloud-spanner` に依存しません。例では `client_type_to_spanvalue` で `Type::code()` / `array_element_type()` をマップします。protojson からの型パースには `spanvalue::type_from_protojson` を使えます。

**制限**: `google-cloud-spanner` 0.34 時点では `Type` に `struct_type()` が公開されていません。STRUCT 列は ExecuteSql メタデータを protojson 経由で `type_from_protojson` に渡すか、クライアントの API 拡張を待つ必要があります。

```rust
let metadata = result_set.metadata().expect("metadata");
let col_types: Vec<Type> = metadata.column_types().iter().map(client_type_to_spanvalue).collect();
let native = NativeValue::I64(row.try_get("n")?);
let formatted = format_result_row(&col_types, &native_values, &config)?;
```

完全な例: [`examples/rust/src/main.rs`](../examples/rust/src/main.rs)

---

## C++（`google-cloud-cpp` / `google::cloud::spanner`）

| 項目 | API |
|---|---|
| クエリ | `Client::ExecuteQuery(SqlStatement{sql})` → `RowStream` |
| 列型 | **高水準 `RowStream` はメタデータ非公開** → 固定クエリでは `google::spanner::v1::Type` を手組み、または低水準 `PartialResultSet` |
| 行値 | `StreamOf<std::tuple<...>>` の強型タプル |
| spanformat | `spanvalue::adapt_client_type`（protobuf 型）, `encode_value`, `format_result_row` |

protobuf C++ 型がビルドに含まれる場合、`adapt_client_type` が `code()` / `struct_type()` 等を protojson 形に変換します。

```cpp
const std::vector<google::spanner::v1::Type> metadata_types = { /* INT64, STRING, BOOL */ };
auto col_types = /* adapt_client_type per field */;
auto rows = client.ExecuteQuery(sql);
for (auto const& row_status : spanner::StreamOf<RowTuple>(rows)) {
    auto const& [n, s, b] = *row_status;
    // encode_value / format_result_row
}
```

完全な例: [`examples/cpp/query_format.cpp`](../examples/cpp/query_format.cpp)（vcpkg — [`examples/cpp/README.md`](../examples/cpp/README.md)）

---

## API 対応表（サマリー）

| 言語 | クライアント列型 API | クライアント行値 API | spanformat API |
|---|---|---|---|
| Python | `metadata.row_type.fields[].type_` | `list(row)` | `adapt_client_type` → `format_result_row` |
| Java | `getColumnType(i)` | `getLong` / `getString` / … | `adaptClientType` → `formatResultRow` |
| Node.js | `response.rowType.fields[].type` | `row[name]`（`json: true`） | `adaptClientType` → `formatResultRow` |
| Ruby | `metadata.row_type.fields[].type` | `row[i]` | `ClientTypeAdapter.adapt` → `format_result_row` |
| PHP | `metadata()['rowType']['fields']` | `$row[name]` | `ClientTypeAdapter::adapt` → `format_result_row` |
| C# | `GetSchemaTable()` → `SpannerDbType` | `GetInt64` / `GetString` / … | `AdaptClientType` → `FormatResultRow` |
| Rust | `metadata().column_types()` | `row.try_get(name)` | `type_from_parts` / `type_from_protojson` → `format_result_row` |
| C++ | 手組み `v1::Type` または protobuf | `StreamOf<tuple>` | `adapt_client_type` → `format_result_row` |

---

## 残課題

| 項目 | 影響 |
|---|---|
| C++ `RowStream` にメタデータなし | 動的 SQL では列型を別経路で取得する必要あり |
| Rust `Type::struct_type()` 未公開 | STRUCT 列の `adapt_client_type` が不完全 |
| PROTO / ENUM の表示名 | 記述子付き `protofmt` は各ポートで優先度低（[`spec/ENCODER.md`](../spec/ENCODER.md) § Out of scope） |

問題や追加パターンは [`examples/README.md`](../examples/README.md) の PR / issue で共有してください。
