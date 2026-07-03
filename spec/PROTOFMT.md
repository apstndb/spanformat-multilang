# protofmt — descriptor-aware PROTO/ENUM value display

このドキュメントは、Go 上流 [`github.com/apstndb/spanvalue/protofmt`](https://pkg.go.dev/github.com/apstndb/spanvalue/protofmt)
の **オプション機能**を multilang 各ポートへ移植するための設計仕様である。
[`spec/FORMAT.md`](FORMAT.md) が規定する **既定の wire ベース表示**（descriptor-free）を変更しない。
protofmt はその上に載せる **任意の表示レイヤー**として扱う。

関連: [`spec/ENCODER.md`](ENCODER.md) § Out of scope (P5)、
[`docs/CLIENT_INTEGRATION.md`](../docs/CLIENT_INTEGRATION.md)（PROTO/ENUM 表示名）。

## 1. Go `protofmt` の役割

### 1.1 スコープの整理

| レイヤー | パッケージ | 記述子 | multilang 状態 |
|---|---|---|---|
| **型フォーマット** | `spantype` | 不要（`proto_type_fqn` 文字列のみ） | **既に Go と同等**（`ProtoEnumMode`） |
| **値フォーマット（既定）** | `spanvalue` プリセット | 不要 | **既に Go と同等**（`conformance.json` 準拠） |
| **値フォーマット（opt-in）** | `spanvalue/protofmt` | **必要** | **未移植（本仕様の対象）** |

`protofmt` は **spanvalue の値表示**のみを拡張する。`FormatType` / `ProtoEnumMode` には触れない。

### 1.2 提供する 2 つのプラグイン

Go 上流（`.refs/spanvalue/protofmt/protofmt.go`）は、
`spanvalue.FormatComplexFunc` として次を返す。

#### `FormatProtoTextValue`

| 項目 | 内容 |
|---|---|
| 対象 | `TypeCode == PROTO` の非 NULL 値 |
| 入力 | `Type.proto_type_fqn`（message の full name）+ wire の base64 文字列 + **Message 型の記述子** |
| 処理 | base64 decode → `proto.Unmarshal`（dynamic message）→ `prototext.Marshal` |
| 出力 | protobuf **text format**（例: `singer_id: 1` `name: "Alice"`） |
| フォールバック | resolver なし / 型未解決 / 空 FQN → `ErrFallthrough`（既存 SIMPLE: readable bytes または `\xHH` エスケープ） |
| エラー | malformed wire、base64 失敗、unmarshal/marshal 失敗 |

#### `FormatEnumNameValue`

| 項目 | 内容 |
|---|---|
| 対象 | `TypeCode == ENUM` の非 NULL 値 |
| 入力 | `Type.proto_type_fqn`（enum の full name）+ wire の decimal 文字列 + **Enum 型の記述子** |
| 処理 | `strconv.ParseInt` → `EnumDescriptor.Values().ByNumber(n)` |
| 出力 | enum **値名**（例: `POP`） |
| フォールバック | resolver なし / 型未解決 / 空 FQN → `ErrFallthrough`（既存: 数値文字列のまま） |
| 既知型・未知番号 | 型は解決できたが番号が未定義、または int32 範囲外 → **数値文字列をそのまま返す**（エラーにしない） |

### 1.3 Resolver（記述子プール）API

```go
type ProtoEnumResolver interface {
    protoregistry.MessageTypeResolver   // FindMessageByName, FindMessageByURL, extensions
    protoregistry.ExtensionTypeResolver
    EnumResolver                        // FindEnumByName(FullName) (EnumType, error)
}
```

ビルダー:

- `ProtoEnumResolverFromFileDescriptorSet(*descriptorpb.FileDescriptorSet)` —
  `protodesc.NewFiles` + `dynamicpb.NewTypes` で dynamic resolver を構築。
  `nil` FDS は空 resolver（エラーなし）。
- `ComposeProtoEnumResolvers(...)` — 順番に lookup。`protoregistry.NotFound` のみ次へ。
  ラップされた NotFound は通常エラーとして返す。

アプリケーション側の責務: `.proto` の読み込み、コンパイル、リモート descriptor の取得、
複数 FDS のマージ。**ライブラリは FDS を渡されたものだけを使う。**

### 1.4 `FormatConfig` への組み込み（Go）

Go 上流は **plugin chain** モデル（v0.8+）:

1. `FormatConfig.FormatComplexPlugins` — `FormatComplexFunc` の配列。
2. 先頭（prepend）のプラグインが先に実行。`ErrFallthrough` で次へ委譲。
3. チェーン末尾が preset の scalar / ARRAY / STRUCT ハンドラ。
4. NULL は `PluginSkippingNull` によりプラグインをスキップし、
   `GetNullString` で既存どおり描画。

典型的な有効化:

```go
fc := spanvalue.SimpleFormatConfig().Clone()
fc = fc.WithComplexPlugin(protofmt.FormatProtoTextValue(opts))
fc = fc.WithComplexPlugin(protofmt.FormatEnumNameValue(opts))
```

または `descriptorAwareConfig` パターン（`protofmt_test.go`）で
`SpannerCLICompatibleFormatConfig` に prototext + enum 名プラグインを prepend。

**LITERAL プリセットは protofmt の対象外。** `CAST(... AS \`fqn\`)` は
descriptor-free の SQL リテラルとして維持する（Go 上流 README も同旨）。

**SPANNER_CLI** も既定では protofmt を prepend しない（base64 wire のまま）。
表示用途で CLI プリセットに載せることは可能だが、multilang 移植の MVP 外とする。

### 1.5 設計上の性質

- **opt-in** — プリセット singleton には含まれない。
- **lenient 既定** — 型未解決時は wire 表示へ fallthrough。
  `OnUnresolved(typeFQN, code)` で strict 化可能（非 nil エラー返却）。
- **prototext 出力は安定しない** — フィールド順・空白は実装依存。
  テストは round-trip（unmarshal → equal）で検証し、バイト完全一致は要求しない。
- **multiline prototext** — `MarshalOptions{Multiline: true}` 使用時、
  セル内に改行が入る。CSV/TSV 等では注意（Go `writer` README 参照）。

## 2. multilang の現状とギャップ

### 2.1 既にあるもの

**型フォーマット（全言語）**

- `proto_type_fqn` + `ProtoEnumMode`（BASE / LEAF / FULL / LEAF_WITH_KIND / FULL_WITH_KIND）
- `testdata/conformance.json` の `type_cases`（`proto`, `enum`, `array_proto`, `struct_readme_example` 等）

**値フォーマット（全言語、descriptor-free 既定）**

[`spec/FORMAT.md`](FORMAT.md) §3.4 および `value_cases` に一致:

| ケース | SIMPLE | LITERAL | SPANNER_CLI |
|---|---|---|---|
| ENUM `42` | `"42"` | `CAST(42 AS \`examples.Genre\`)` | `"42"` |
| PROTO bytes | readable bytes または `\xHH` | `CAST(b"..." AS \`examples.Book\`)` | base64 wire |
| NULL | preset の null string | 同左 | 同左 |

**エンコーダ（gcvctor）**

- FQN + `bytes` / `int` wire 形式の構築は全ポートで実装済み（[`spec/ENCODER.md`](ENCODER.md)）。

### 2.2 ギャップ

| 項目 | Go `protofmt` | multilang 現状 |
|---|---|---|
| ENUM → 値名表示 | `FormatEnumNameValue` | 数値文字列のみ |
| PROTO → prototext | `FormatProtoTextValue` | readable bytes / エスケープのみ |
| Resolver / FDS | `ProtoEnumResolver` | なし |
| Plugin chain | `FormatComplexPlugins` + `ErrFallthrough` | preset 固定分岐（`if preset == SIMPLE`） |
| 別 conformance | Go `protofmt` テスト（upstream） | `conformance.json` に protofmt ケースなし |

**アーキテクチャ差が最大の移植前提:** protofmt 以前に、各言語で
「prepend 可能な value formatter hook」か、同等の `with_descriptors` 拡張が必要。

## 3. 推奨アーキテクチャ

### 3.1 原則

1. **コアは zero-dep のまま** — 既定 `format_value` の挙動と `conformance.json` は不変。
2. **protofmt は optional feature** — 言語ごとに extra / feature flag / 別パッケージ。
3. **テスト資産は言語中立** — 共有 `FileDescriptorSet` + 別 conformance ファイル。
4. **LITERAL は触らない** — protofmt は主に SIMPLE（と将来オプションの表示系プリセット）向け。

### 3.2 Option 評価

| Option | 概要 | 採用 |
|---|---|---|
| **A** 各言語で descriptor lookup 完全移植 | Go 最大互換、動的スキーマ | **本番パス（Phase 2+）** |
| **B** 事前計算 JSON インデックス | FQN → 表示名の静的 map | **conformance / zero-dep 簡易モードのみ** |
| **C** optional dependency | `spanvalue[protofmt]` 等 | **推奨（コア維持）** |
| **D** well-known types のみ | `google.protobuf.*` 固定 | 不採用（ユーザー定義 PROTO をカバーできない） |

**ハイブリッド: C + B** — 本番は C（各言語の protobuf ランタイム）。
`testdata/protofmt/` の FDS と期待値は全言語共通。C++/PHP 等は B の lookup テーブルで
ENUM 名テストのみ先行可能（prototext は A 必須）。

### 3.3 Descriptor pool API（言語中立インターフェース）

各言語は idiomatic な型名でよいが、**セマンティクスは Go に合わせる**:

```
interface ProtoEnumResolver {
    find_message_by_name(fqn: string) -> MessageType | NOT_FOUND
    find_enum_by_name(fqn: string) -> EnumType | NOT_FOUND
    // extensions / FindMessageByURL は PROTO prototext（Any 展開等）で必要な言語のみ
}

function resolver_from_file_descriptor_set(fds: bytes | FileDescriptorSet) -> ProtoEnumResolver
function compose_resolvers(...resolvers: ProtoEnumResolver) -> ProtoEnumResolver
```

`NOT_FOUND` は言語固有の sentinel（Go: `protoregistry.NotFound`）とし、
ラップされた not-found は通常エラーとして扱う。

### 3.4 別 conformance ファイル（提案）

既存 [`testdata/conformance.json`](../testdata/conformance.json) は **変更しない**。

新規: `testdata/protofmt_conformance.json`（名称は実装時に確定）

```jsonc
{
  "spec_version": "0.1.0",
  "descriptor_set": "testdata/protofmt/example.fds.bin",  // 共有 FDS
  "value_cases": [
    {
      "name": "enum_known",
      "type": { "code": "ENUM", "protoTypeFqn": "example.music.Genre" },
      "value": "1",
      "expected_simple": "POP"   // resolver 有効時のみ
    },
    {
      "name": "no_resolver_fallthrough",
      "type": { "code": "ENUM", "protoTypeFqn": "example.music.Genre" },
      "value": "1",
      "resolver": null,
      "expected_simple": "1"    // 既存 conformance と同一
    }
    // proto_singer, enum_unknown_number, nested_struct, strict_unresolved, ...
  ]
}
```

- **SIMPLE のみ**を主対象（LITERAL/SPANNER_CLI は既存 conformance が継続担当）。
- PROTO ケースは **round-trip 検証**または正規化比較（完全一致非要求）。
- `tools/gen-testdata` に protofmt 生成モードを追加し、Go 上流を expected の source of truth にする。

### 3.5 Value formatter 拡張（plugin 相当）

移植時は次のいずれか（言語の慣習に合わせる）:

**パターン A — `FormatConfig` 拡張（推奨）**

```
FormatConfig {
    preset, null_string, quote,           // 既存
    descriptor_resolver: Option<Resolver>, // 新規、デフォルト None
    on_unresolved: Option<Fn>,             // strict 用、任意
}
```

resolver が `None` のとき、コードパスは現行と同一（ゼロコスト分岐）。

**パターン B — 明示的プラグインリスト**

Go と同型の `with_complex_plugin` / `prepend_formatter`。
将来 JSON 出力プラグイン等にも再利用可能。

**MVP はパターン A**（API 表面が小さい）。内部実装はパターン B と等価な
prepend チェーンでよい。

## 4. 言語別実現可能性と工数

工数: **S**（数日） / **M**（1–2 週） / **L**（2 週+）。
プラグイン基盤の初回設計は Python spike に含め、他言語はパターンコピーで **+S〜M**。

| 言語 | 工数 | protobuf / descriptor | 備考 |
|---|---|---|---|
| **Python** | **S–M** | `google.protobuf`（optional extra） | `DescriptorPool`, `text_format`, `Parse`。**Phase 1 spike 先** |
| **Java** | **S–M** | 既存 `protobuf-java` 依存 | `Descriptors`, `DynamicMessage`, `TextFormat` |
| **Node.js** | **M** | optional peer: `protobufjs` or `@bufbuild/protobuf` | コア zero-dep 維持 |
| **C#** | **M** | optional: `Google.Protobuf` | `Apstndb.SpanValue.Protofmt` 分割が自然 |
| **Rust** | **M–L** | feature: `prost-reflect` or `protobuf` crate | 現状 `serde_json` のみ |
| **PHP** | **M–L** | optional: `google/protobuf` composer suggest | コア zero-dep |
| **Ruby** | **M–L** | optional: `google-protobuf` gem | コア zero-dep |
| **C++** | **L** | optional CMake target + protobuf | 既定ビルド protobuf なし |

**横断（全言語）:** fallthrough / NULL skip / nested ARRAY·STRUCT 再帰 — 初回 **M**。

## 5. 段階的実装計画

### Phase 0 — 仕様固定（本ドキュメント）

- [x] `spec/PROTOFMT.md` 作成
- [ ] `testdata/protofmt_conformance.json` スキーマとケース一覧の確定
- [ ] `FORMAT.md` / README からのリンク
- [ ] `ENCODER.md` P5 を「Phase 0 仕様あり」へ更新（任意）

**成果物:** 設計合意。既存テスト・API 変更なし。

### Phase 1 — Python spike（ENUM 名のみ MVP）

- optional extra: `pip install spanvalue[protofmt]`（`protobuf` 依存）
- `FormatConfig.with_descriptor_resolver(pool)` または同等 API
- `testdata/protofmt/` の最小 FDS + `protofmt_conformance.json` の ENUM ケース
- 既存 `pytest` / `conformance.json` は **回帰なし**

**成功基準:** resolver ありで ENUM 名、なしで既存と同一。

### Phase 2 — Python PROTO prototext + 他言語（Java, Node）

- `FormatProtoTextValue` 相当
- multiline / nested STRUCT·ARRAY 内 PROTO
- Java → Node の順で横展開

### Phase 3 — C#, Rust

- feature flag / 別パッケージ
- `ComposeProtoEnumResolvers`、well-known types（`google.protobuf.Duration` 等）

### Phase 4 — C++, PHP, Ruby

- optional ビルド / composer suggest / gem
- ENUM lookup テーブル限定モード（B）で conformance 先行可、prototext は A 必須

### 新規 conformance ケース（案）

| name | 検証内容 |
|---|---|
| `enum_known` | 既知番号 → 値名 |
| `enum_unknown_number` | 未定義番号 → 数値文字列 |
| `enum_out_of_range` | int32 範囲外 → 数値文字列 |
| `enum_null` | NULL → null string（resolver 関係なく既存と同一） |
| `proto_singer` | message bytes → prototext（round-trip） |
| `proto_well_known` | `google.protobuf.Duration` |
| `proto_unreadable_wire` | malformed → エラー |
| `no_resolver_fallthrough` | resolver なし → `conformance.json` と同一 |
| `unresolved_strict` | `on_unresolved` → エラー |
| `nested_array_enum` | ARRAY 内 ENUM にも適用 |
| `nested_struct_proto` | STRUCT フィールド内 PROTO |

## 6. API スケッチ（言語別 idiomatic 名）

### 6.1 Python（参考）

```python
from spanvalue import simple_format_config, format_value
from spanvalue.protofmt import (
    resolver_from_file_descriptor_set,
    compose_resolvers,
)

pool = resolver_from_file_descriptor_set(fds_bytes)
config = simple_format_config().with_descriptor_resolver(pool)

# 既存 API は不変（resolver デフォルト None）
format_value(typ, value, config)  # ENUM → "POP", PROTO → prototext

# strict
config = config.with_on_unresolved(
    lambda fqn, code: UnresolvedDescriptorError(fqn)
)
```

### 6.2 Java

```java
ProtoEnumResolver pool = ProtoEnumResolver.fromFileDescriptorSet(fds);
FormatConfig config = FormatConfigs.simple()
    .withDescriptorResolver(pool);
```

### 6.3 Rust（feature `protofmt`）

```rust
let pool = ProtoEnumResolver::from_file_descriptor_set(&fds)?;
let config = FormatConfig::simple()?
    .with_descriptor_resolver(pool);
```

### 6.4 共通セマンティクス

| 条件 | 動作 |
|---|---|
| `resolver == None` | 現行 `format_value` と同一 |
| `resolver` あり、型解決成功 | protofmt 出力 |
| `resolver` あり、型未解決 | fallthrough → 現行 wire 表示（lenient） |
| `on_unresolved` がエラー返却 | フォーマット失敗 |
| `preset == LITERAL` | **protofmt を適用しない**（常に CAST 形式） |
| `value == NULL` | protofmt をスキップ、null string |
| nested ARRAY / STRUCT | 再帰的に同ルール |

### 6.5 Go 上流との対応表

| Go | multilang（提案） |
|---|---|
| `protofmt.FormatProtoTextValue(opts)` | `with_descriptor_resolver` 内の PROTO ハンドラ、または `ProtoTextFormatter` |
| `protofmt.FormatEnumNameValue(opts)` | 同上 ENUM ハンドラ |
| `ProtoEnumResolverFromFileDescriptorSet` | `resolver_from_file_descriptor_set` |
| `ComposeProtoEnumResolvers` | `compose_resolvers` |
| `fc.WithComplexPlugin(p)` | `config.with_complex_plugin(p)` または resolver 統合 |
| `ErrFallthrough` | `Fallthrough` sentinel / `Option::None` |
| `OnUnresolved` | `with_on_unresolved` |

## 7. 非目標（明示）

- `spantype` の型表示に記述子を使うこと（不要）
- LITERAL / SPANNER_CLI の既定挙動変更
- 既存 `testdata/conformance.json` の期待値変更
- `.proto` コンパイルやリモート schema 取得のライブラリ内蔵
- prototext 出力のバイト安定性保証
- リリースタグ付け（実装フェーズでもユーザー明示まで行わない）

## 8. 参照実装

- Go: [`.refs/spanvalue/protofmt/`](../.refs/spanvalue/protofmt/)（workspace symlink → `apstndb/spanvalue`）
- 既定値仕様: [`spec/FORMAT.md`](FORMAT.md) §3.4（ENUM / PROTO）
- 監査・優先度: [`spec/ENCODER.md`](ENCODER.md) § Out of scope (P5)
