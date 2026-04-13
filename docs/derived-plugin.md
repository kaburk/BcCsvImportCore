# BcCsvImportCore 派生プラグイン早見表

派生プラグインを作るときの共通パターンだけを短くまとめた文書です。  
実際の作業順や詳細コード例は `create-custom-import-plugin.md` を参照してください。

---

## 既存の派生プラグイン一覧

| プラグイン名 | 対象テーブル | 特徴 |
|-------------|------------|------|
| `BcCsvImportSampleProducts` | `bc_csv_sample_products` | 1行1レコードのシンプルな実装 |
| `BcCsvImportSampleOrders` | `bc_csv_sample_orders` | 1行1受注（単一テーブル） |
| `BcCsvImportBlogPosts` | `blog_posts`（bc-blog 管理） | 複合キー重複判定・jobMeta 活用・固有UI |
| `BcCsvImportSampleOrderDetails` | `bc_order_detail_orders` + `bc_order_detail_details` | 1対多（受注ヘッダー＋明細）の実装 |

---

## 最小構成

```text
MyImportPlugin/
├── composer.json
├── config.php
├── config/
│   ├── permission.php
│   ├── setting.php
│   └── Migrations/
├── src/
│   ├── MyImportPluginPlugin.php
│   ├── Controller/Admin/MyImportsCsvImportsController.php
│   ├── Model/Entity/MyEntity.php
│   ├── Model/Table/MyTable.php
│   └── Service/MyImportCsvImportService.php
└── templates/Admin/MyImportsCsvImports/
```

- 追加UIが不要ならテンプレートは省略し、Core のテンプレートを再利用する
- 配布や公開を考えるなら `composer.json` は置く前提で進める

---

## 必須実装

### サービス

`CsvImportService` を継承し、最低限次を実装する。

```php
public function getTableName(): string;
public function getColumnMap(): array;
public function getDuplicateKey(): string;
public function buildEntity(array $row): EntityInterface;
```

### コントローラー

- `CsvImportsController` を継承する
- `createImportService()` で専用サービスを返す
- 追加UIが不要なら Core テンプレートをそのまま使う

### 設定

- メニューは `BcApp.adminNavigation` にプラグイン固有キーで登録する
- UI 設定は `BcCsvImportCore.*` ではなく `自プラグイン名.*` を使う

### 権限

- `config/permission.php` を作成する
- 権限キーはプラグイン固有名にする
- 独自アクションを追加したら permission にも追加する

---

## よく使う拡張ポイント

| 拡張ポイント | 用途 |
|--------------|------|
| `createImportService()` | プラグインごとに異なるサービスを差し替える |
| `createAdminService()` | 画面に追加の View 変数を渡す |
| `buildDuplicateSearchConditions()` | 複合キーなどの検索条件を作る |
| `buildDuplicateIdentity()` | CSV 行側の重複識別子を組み立てる |
| `buildDuplicateIdentityFromEntity()` | 既存エンティティ側の重複識別子を組み立てる |
| `validateBatch()` / `processBatch()` | 1対多や特殊保存処理を組み込む |

---

## パターン別メモ

### 1対多CSV

- `getDuplicateKey()` は空文字でバイパスすることが多い
- `validateBatch()` と `processBatch()` を両方オーバーライドする
- `processBatch()` で親キー単位にグループ化して保存する
- `replace` では子テーブルを先、親テーブルを後で削除する

### jobMeta を使うケース

- 画面で選んだ補助パラメータを `createJob(..., ['meta' => ...])` で保存する
- `buildEntity()` 内で `getJobMeta()` から参照する
- BlogPosts の `blog_content_id` が典型例

### テンプレート再利用

| ケース | 対応 |
|--------|------|
| Core と同じUIでよい | Core テンプレートを再利用する |
| ドロップダウンなど追加入力がある | 専用テンプレートを作る |
| 完全に別UIが必要 | 専用テンプレートを作る |

### GenerateTestCsvCommand

- `AbstractGenerateTestCsvCommand` を継承する
- `defaultName()`、`getService()`、`getFilenamePrefix()`、`buildRow()`、`getErrorPatterns()` などを実装する
- 1対多CSVは `buildRow()` 側で複数明細を連続行として出力する

### cleanup コマンド

- cleanup は `BcCsvImportCore.cleanup` を使う
- 派生プラグインごとの `*.cleanup` コマンドは作らない

---

## チェックリスト

- [ ] migration を作成した（既存テーブルを使う場合は不要）
- [ ] `config.php` を作成した
- [ ] `config/setting.php` のメニューキーをプラグイン名にした
- [ ] `config/setting.php` の UI 設定キーを `自プラグイン名.*` にした
- [ ] `config/permission.php` を作成し、URL と権限キーをプラグインに合わせた
- [ ] コントローラーで `createImportService()` を実装した
- [ ] サービスで `getTableName / getColumnMap / getDuplicateKey / buildEntity` を実装した
- [ ] Core テンプレート再利用時の表示確認を行った
- [ ] 管理画面からプラグインを有効化した
- [ ] `bin/cake plugin assets symlink` が必要なら実行した
