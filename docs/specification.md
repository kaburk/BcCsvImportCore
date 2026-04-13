# BcCsvImportCore 詳細仕様

この文書を `BcCsvImportCore` の正式な詳細仕様として扱います。  
概要や文書案内は `spec.md`、派生プラグインの詳細手順は `create-custom-import-plugin.md`、早見表は `derived-plugin.md` を参照してください。

## 概要

BcCsvImportCore は、baserCMS5 管理画面から CSV 一括インポートを行うための汎用プラグインです。
AJAX による分割処理とジョブテーブルを組み合わせることで、大量データでもタイムアウトしにくい構成を取っています。

インポート対象テーブルや列定義はサービスクラスを継承して差し替えられるため、
実アプリケーション向けのインポートプラグインを作る際の基盤としても利用できます。

---

## プラグイン構成

### コンポーネント

| コンポーネント | 役割 |
|----------------|------|
| `CsvImportService` | インポート処理の抽象基底クラス。継承して各プラグインを実装する |
| `bc_csv_import_jobs` | ジョブの状態・進捗・エラー件数を管理するテーブル |
| 各派生プラグインのサービス | `CsvImportService` を継承した具象クラス |
| 各派生プラグインのテーブル | 実際の取り込み先テーブル |

### 同梱の派生プラグイン

| プラグイン | 説明 |
|-----------|------|
| `BcCsvImportSampleProducts` | 動作確認用のサンプル商品インポート |
| `BcCsvImportSampleOrders` | 1行1受注のサンプル受注インポート |
| `BcCsvImportSampleOrderDetails` | 1対多のサンプル受注ヘッダー・受注明細インポート |
| `BcCsvImportBlogPosts` | ブログ選択UI付きのブログ記事インポート |

複数の派生プラグインを同時に有効化できるよう、各プラグインは独自コントローラーと独自メニューを持ちます。

---

## インポートの動作

### バリデーションモード

アップロード時に **strict** か **lenient** を選択します。

| モード | 動作 |
|--------|------|
| **strict** | まず全件バリデーションを実行する。エラーが 0 件の場合のみ登録フェーズへ進む。エラーがあればジョブを `failed` で保持し、登録を行わない |
| **lenient** | バリデーションをスキップして直接登録フェーズへ進む。バリデーションエラーの行はその都度読み飛ばしながら処理を続ける |

### インポート方式

**append** か **replace** を選択します。

| 方式 | 動作 |
|------|------|
| **append** | 既存データを残したまま追記・更新する |
| **replace** | 登録開始直前に対象テーブルの既存データを全削除してから取り込む。`TRUNCATE` に失敗した場合は `DELETE` 全件削除へフォールバックする |

replace の注意点:

- 必ず確認ダイアログを出す
- strict + エラーありの場合は削除を行わない（登録フェーズへ進まないため）
- lenient の場合は最初の登録バッチ直前に削除する
- 再開時の二重削除は `target_cleared` フラグで防いでいる

### 重複処理

`getDuplicateKey()` で指定したカラムで既存レコードと照合し、重複が見つかった場合の挙動を選択します。

| モード | 動作 |
|--------|------|
| **skip** | 既存レコードを変更しない（読み飛ばす） |
| **overwrite** | 既存レコードを CSV の値で上書き更新する |
| **error** | 重複をバリデーションエラーとして扱い、エラーCSVに出力する |

---

## CSV 仕様

### ヘッダー検証

アップロード時に CSV の 1 行目（ヘッダ行）と `getColumnMap()` が返すラベル一覧を照合します。
一致しない場合は登録処理を行わずにエラーをインライン表示します（アラートダイアログは使いません）。

エラーメッセージの形式（不一致があった場合）:

```
CSVのヘッダが一致しません。

■ 正しいヘッダ行
商品名, 価格, 在庫数

■ アップロードされたヘッダ行
商品名, 値段, 在庫数量
```

不一致の内容は `logs/csv_import.log` にも `warning` として記録されます。

### エラーCSV

エラー行を含む CSV として再ダウンロードできます。
元のデータ列の末尾に「行番号」「エラー内容」が追加された形式で出力されます。

---

## エラー処理

- エラーは JSON Lines 形式で `error_log_path` のファイルに追記する
- 管理画面ではエラー一覧をテーブル表示し、エラーCSVをダウンロードできる
- エラーCSVダウンロードはストリームレスポンスで逐次出力する（メモリ節約）
- 未完了ジョブ一覧では先頭数件だけプレビュー表示する

## ログ出力

プラグイン起動時に `logs/csv_import.log` へ書き出す `csv_import` ログチャネルを自動設定します。  
設定は `BcCsvImportCorePlugin::bootstrap()` で行い、既に設定済みの場合は上書きしません。

記録するイベント:

| レベル | イベント | 内容 |
|--------|----------|------|
| info | `job_created` | token, 対象テーブル, 総件数, mode, strategy, duplicate_mode |
| info | `validate_batch` | token, offset, processed, error_count |
| info | `process_batch` | token, offset, success, skip, error_count |
| info | `job_completed` | token, 総件数, success, skip, error_count |
| info | `job_cancelled` | token, processed, total |
| info | `job_deleted` | token, status |
| info | `target table cleared` | token, table, method（truncate / delete） |
| warning | `truncate failed` | token, table, エラーメッセージ |
| warning | `header_mismatch` | ファイル名, expected, actual |
| error | `upload_error` | エラーメッセージ |
| error | `validate_batch_error` | token, エラーメッセージ |
| error | `process_batch_error` | token, エラーメッセージ |
| error | `cancel_error` | token, エラーメッセージ |

---

## 管理画面

### アップロード画面

- CSVファイル選択
- テンプレートCSVダウンロード（`getColumnMap()` の label / sample から動的生成）
- 派生プラグインによっては追加のCSVダウンロード導線を配置できる
  - 例: `BcCsvImportBlogPosts` では選択中ブログの記事CSV一括ダウンロード
- **オプション**（アコーディオン、デフォルト閉じ）
  - 文字コード（auto / UTF-8 / Shift-JIS）
  - バリデーションモード（strict / lenient）
  - インポート方式（append / replace）
  - 重複データの処理（skip / overwrite / error）

各 select / radio には baserCMS 管理画面の標準 CSS クラスが適用されます。  
`showXxx` を `false` にすると対応する選択 UI が非表示になり、`defaultXxx` の値で固定動作します。

### ジョブ一覧

未完了ジョブ（`pending` / `processing` / `failed`）と、最近の履歴（`completed` / `cancelled`）を表示します。

| 操作 | 対象 |
|------|------|
| 再開 | pending / processing |
| エラーCSVダウンロード | failed / completed（エラーあり） |
| 削除 | すべてのジョブ |

最近の履歴はアコーディオンでデフォルト閉じ、新しい順に最大 20 件表示します。

### プログレスバー

| フェーズ | 色 |
|----------|----|
| 検証中（validate） | 黄色（`#d9a321`） |
| 登録中（import） | 緑（`#4d9f44`） |

フェーズが切り替わるたびに色がトランジションします。

---

## 派生プラグインでの拡張

### createImportService()

`CsvImportsController` を継承した専用コントローラーでオーバーライドします。  
プラグインごとに異なる `CsvImportService` 実装を渡す唯一のエントリポイントです。

```php
protected function createImportService(): CsvImportServiceInterface
{
    return new MyOrdersCsvImportService();
}
```

### createAdminService()

アップロード画面に追加の View 変数を渡したい場合にオーバーライドします。  
`BcCsvImportBlogPosts` ではブログ一覧のプルダウン生成に利用しています。

### jobMeta

アップロード時のリクエストパラメータをジョブ単位で JSON 保存し、`buildEntity()` 内で参照できる仕組みです。  
`BcCsvImportBlogPosts` では `blog_content_id`（どのブログに取り込むか）の受け渡しに使っています。

```php
$blogContentId = (int)$this->getJobMeta('blog_content_id', 0);
```

### 重複判定拡張

単一カラムによる重複判定では対応できないケース（複合キーなど）は以下をオーバーライドします。

| メソッド | 役割 |
|----------|------|
| `buildDuplicateSearchConditions()` | 重複候補の検索条件をカスタマイズする |
| `buildDuplicateIdentity()` | 入力データ（CSV行）側の重複識別子を定義する |
| `buildDuplicateIdentityFromEntity()` | 既存エンティティ側の重複識別子を定義する |

`BcCsvImportBlogPosts` ではこれらを使い、`blog_content_id + name`（ブログID＋スラッグ）の複合キーで重複を判定しています。

---

## 設定

`config/setting.php` がデフォルト設定で、環境差分は `config/setting_customize.php` で上書きします。  
`config/setting_customize.php.default` が雛形として添付されており、`config/setting_customize.php` 本体は Git 管理対象外です。

### 主な設定キー（`BcCsvImportCore.*`）

| キー | デフォルト | 説明 |
|------|-----------|------|
| `importServiceClass` | `null` | 旧互換の設定キー。複数インポートを共存させる場合は各プラグインが独自コントローラーでサービスを切り替えるため不要 |
| `csvExpireDays` | `3` | 一時CSVファイルの保持日数 |
| `batchSize` | `1000` | 1回の AJAX で処理する行数 |
| `showOptionSection` | `true` | オプションアコーディオン全体の表示可否。`false` にすると全オプションが非表示になる |
| `showEncodingSelect` | `true` | 文字コード選択の表示可否 |
| `showModeSelect` | `true` | バリデーションモード選択の表示可否 |
| `showImportStrategySelect` | `true` | インポート方式選択の表示可否 |
| `showDuplicateModeSelect` | `true` | 重複処理選択の表示可否 |
| `defaultEncoding` | `'auto'` | 文字コードの初期値。`showEncodingSelect: false` のときは固定値として使われる |
| `defaultMode` | `'strict'` | バリデーションモードの初期値 |
| `defaultImportStrategy` | `'append'` | インポート方式の初期値 |
| `defaultDuplicateMode` | `'skip'` | 重複処理の初期値 |

`showOptionSection` を `false` にすると UI 全体が非表示になりますが、  
各 `defaultXxx` の値は hidden input として送信されるため処理は正常に動作します。

### 派生プラグインの UI 設定

複数の派生プラグインを同時有効化する場合、各プラグインが `BcCsvImportCore.*` を上書きしあうと  
ロード順によって設定が踏みにじられる問題が生じます。

そのため `CsvImportsController::resolveUiSettings()` は次の優先順位で設定を解決します。

1. **`プラグイン名.*`** — 自プラグイン固有の設定（例: `BcCsvImportSampleOrders.showImportStrategySelect`）
2. **`BcCsvImportCore.*`** — 上記が `null` の場合のフォールバック
3. **ハードコードデフォルト** — 両方とも未設定の場合

派生プラグインの `config/setting.php` で UI を制御する場合は、`BcCsvImportCore.*` を上書きするのではなく  
**自プラグインのキー**で記述してください。

```php
// BcCsvImportSampleOrders/config/setting.php
return [
    'BcCsvImportSampleOrders' => [
        'showImportStrategySelect' => false,  // append 固定
        'defaultImportStrategy'    => 'append',
        'showDuplicateModeSelect'  => false,  // skip 固定
        'defaultDuplicateMode'     => 'skip',
    ],
];
```

テンプレートは view 変数（`$showImportStrategy` など）を参照するだけで、  
`Configure` を直接読まないため、設定の衝突が起きません。

## 性能上の工夫

### CSV を毎回先頭から読み直さない

ジョブテーブルに `validate_position` / `import_position` としてファイルの読込位置（バイトオフセット）を保持します。  
バッチ処理の再開時は `fseek()` でその位置から読み始めるため、100万件のCSVでも毎回先頭から読み直す必要がありません。

### 重複確認を1行ごとにクエリしない

重複チェックを1行ずつ `SELECT` すると、バッチ1,000件で最大1,000回クエリが走ります。  
そのため、バッチ内の重複キーをまとめて `WHERE key IN (...)` で一括取得し、メモリ上のマップで参照します。

### 空行を処理件数から除外する

CSV に空行が混入していても、総件数・処理済件数のカウントおよび処理対象そのものから除外します。  
空行の有無で進捗表示や結果件数がずれることを防ぎます。

## DB設計

### `bc_csv_import_jobs`

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AUTO | |
| job_token | varchar(255) | ジョブ識別子（ユニーク） |
| job_meta | text | ジョブ固有の追加パラメータ（JSON）。`buildEntity()` から `getJobMeta()` で参照できる |
| target_table | varchar(100) | 対象テーブル |
| phase | varchar(20) | `validate` / `import` |
| total | int | 総件数 |
| processed | int | 現在フェーズの処理済件数 |
| success_count | int | 成功件数 |
| error_count | int | エラー件数 |
| skip_count | int | 内部集計用のスキップ件数 |
| status | varchar(20) | `pending` / `processing` / `completed` / `failed` / `cancelled` |
| mode | varchar(20) | `strict` / `lenient` |
| import_strategy | varchar(20) | `append` / `replace` |
| duplicate_mode | varchar(20) | `skip` / `overwrite` / `error` |
| csv_path | varchar(255) | 一時CSVファイルパス |
| validate_position | bigint | validate フェーズの読込位置 |
| import_position | bigint | import フェーズの読込位置 |
| target_cleared | boolean | replace で全削除済みか |
| error_log_path | varchar(255) | エラーログファイルパス（JSON Lines） |
| expires_at | datetime | 期限 |
| started_at | datetime | 開始日時 |
| ended_at | datetime | 終了日時 |
| created | datetime | |
| modified | datetime | |

### `bc_csv_sample_products`（BcCsvImportSampleProducts）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AUTO | |
| name | varchar(255) | 商品名（必須） |
| sku | varchar(100) | 商品コード（重複チェックキー） |
| price | int | 価格 |
| stock | int | 在庫数 |
| category | varchar(100) | カテゴリ |
| description | text | 説明 |
| status | boolean | 公開フラグ |
| created | datetime | |
| modified | datetime | |

### `bc_csv_sample_orders`（BcCsvImportSampleOrders）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AUTO | |
| order_no | varchar(50) | 受注番号（必須・ユニーク、重複チェックキー） |
| customer_name | varchar(255) | 顧客名（必須） |
| customer_email | varchar(255) | メールアドレス |
| customer_tel | varchar(30) | 電話番号 |
| product_name | varchar(255) | 商品名（必須） |
| quantity | int | 数量 |
| unit_price | int | 単価 |
| total_price | int | 合計金額 |
| status | varchar(30) | ステータス（デフォルト: `new`） |
| ordered_at | datetime | 受注日時 |
| created | datetime | |
| modified | datetime | |

### `bc_csv_sample_order_headers`（BcCsvImportSampleOrderDetails）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AUTO | |
| order_no | varchar(50) | 受注番号（必須・ユニーク） |
| customer_name | varchar(255) | 顧客名（必須） |
| customer_email | varchar(255) | メールアドレス |
| customer_tel | varchar(30) | 電話番号 |
| status | varchar(30) | ステータス（デフォルト: `new`） |
| ordered_at | datetime | 受注日時 |
| created | datetime | |
| modified | datetime | |

### `bc_csv_sample_order_details`（BcCsvImportSampleOrderDetails）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AUTO | |
| order_id | int | 受注ヘッダーID |
| order_no | varchar(50) | 受注番号（参照用） |
| product_sku | varchar(100) | 商品コード |
| product_name | varchar(255) | 商品名（必須） |
| quantity | int | 数量 |
| unit_price | int | 単価 |
| line_total | int | 行合計 |
| created | datetime | |
| modified | datetime | |

## Migration 構成

`BcCsvImportCore` 本体の初期 migration は次の 1 本です。

- `BcCsvImportCore/config/Migrations/20260402000001_CreateBcCsvImportJobs.php`

対象テーブル用の migration は各派生プラグイン側に持ちます。

- `BcCsvImportSampleProducts/config/Migrations/20260402000001_CreateBcCsvSampleProducts.php`
- `BcCsvImportSampleOrders/config/Migrations/20260403000001_CreateBcCsvSampleOrders.php`
- `BcCsvImportSampleOrderDetails/config/Migrations/20260403000001_CreateBcOrderDetailOrders.php`
- `BcCsvImportSampleOrderDetails/config/Migrations/20260403000002_CreateBcOrderDetailDetails.php`
- `BcCsvImportBlogPosts` は migration なし（`blog_posts` テーブルは `bc-blog` が管理）

アプリケーション上の Model alias:

- サンプル商品: `BcCsvImportSampleProducts.BcSampleProducts`
- サンプル受注: `BcCsvImportSampleOrders.BcSampleOrders`
- 1対多受注ヘッダー: `BcCsvImportSampleOrderDetails.BcCsvSampleOrders`
- 1対多受注明細: `BcCsvImportSampleOrderDetails.BcCsvSampleOrderDetails`
- ブログ記事: `BcBlog.BlogPosts`

`config/Migrations/schema-dump-default.lock` は生成物であり、Git 管理対象外です。

## BcCsvImportBlogPosts 固有設定

`BcCsvImportBlogPosts/config/setting.php` の `BcCsvImportBlogPosts.*` キーで以下を設定しています。

### UI 設定

| キー | 値 | 説明 |
|------|----|------|
| `showImportStrategySelect` | `true` | インポート方式選択を表示する |
| `defaultImportStrategy` | `'append'` | 初期値は追記モード |
| `showDuplicateModeSelect` | `true` | 重複処理選択を表示する |
| `defaultDuplicateMode` | `'skip'` | 初期値はスキップ |

### サービス設定

| キー | デフォルト | 説明 |
|------|-----------|------|
| `blogCategoryNotFound` | `'create'` | CSVのカテゴリ名が DB に存在しない場合の挙動。`'error'`（その行をスキップ）/ `'create'`（自動作成） |
| `blogTagNotFound` | `'create'` | CSVのタグが DB に存在しない場合の挙動。`'ignore'`（無視）/ `'error'`（スキップ）/ `'create'`（自動作成） |

> **補足:** サービス設定（`blogCategoryNotFound` / `blogTagNotFound`）は `BlogPostsCsvImportService` が
> `Configure::read('BcCsvImportBlogPosts.*')` で参照しています。
> UI 設定を変更したいときも `BcCsvImportBlogPosts.*` キーを使用してください。

## 今後の改善候補

- bulk insert / bulk upsert による更なる高速化
- サービス単位の削除戦略オーバーライド

---

## コマンド

### BcCsvImportCore.cleanup

期限切れ（`expires_at` 超過）のジョブレコードと付随する一時ファイル（CSV・エラーログ）を削除する。

```bash
# 実際に削除する
docker compose exec bc-php bash -c "cd /var/www/html && bin/cake BcCsvImportCore.cleanup"

# ドライラン（件数確認のみ、削除しない）
docker compose exec bc-php bash -c "cd /var/www/html && bin/cake BcCsvImportCore.cleanup --dry-run"
```

実行結果例:

```
クリーンアップ完了: ジョブ 5 件・ファイル 8 件を削除しました。
```

定期実行する場合は cron やサーバーのスケジューラで呼び出してください。

---

## 権限定義（permission.php）

コアプラグイン（`BcCsvImportCore`）および全ての派生プラグインは
`config/permission.php` にアクセスルール初期値を定義しています。

| プラグイン | 権限キー | アクション数 |
|----------|---------|------------|
| `BcCsvImportCore` | `CsvImportsAdmin` | 9 |
| `BcCsvImportBlogPosts` | `BlogPostsCsvImportsAdmin` | 10（`download_posts` 含む） |
| `BcCsvImportSampleOrders` | `SampleOrdersCsvImportsAdmin` | 9 |
| `BcCsvImportSampleOrderDetails` | `SampleOrderDetailsCsvImportsAdmin` | 9 |
| `BcCsvImportSampleProducts` | `SampleProductsCsvImportsAdmin` | 9 |

---

## ユニットテスト

### テストスイート

`phpunit.xml.dist` に以下のスイートを定義しています。

| スイート | ディレクトリ |
|---------|-------------|
| `BcCsvImportCore` | `plugins/BcCsvImportCore/tests/TestCase` |
| `BcCsvImportSampleOrders` | `plugins/BcCsvImportSampleOrders/tests/TestCase` |
| `BcCsvImportSampleOrderDetails` | `plugins/BcCsvImportSampleOrderDetails/tests/TestCase` |
| `BcCsvImportSampleProducts` | `plugins/BcCsvImportSampleProducts/tests/TestCase` |
| `BcCsvImportBlogPosts` | `plugins/BcCsvImportBlogPosts/tests/TestCase` |

### 実行例（Docker 内）

```bash
# BcCsvImportCore のみ
docker compose exec bc-php bash -c \
  "cd /var/www/html && vendor/bin/phpunit --testsuite BcCsvImportCore --no-coverage"

# 全 CsvImport スイートまとめて実行
docker compose exec bc-php bash -c \
  "cd /var/www/html && vendor/bin/phpunit \
    --testsuite BcCsvImportCore \
    --testsuite BcCsvImportSampleOrders \
    --testsuite BcCsvImportSampleOrderDetails \
    --testsuite BcCsvImportSampleProducts \
    --testsuite BcCsvImportBlogPosts \
    --no-coverage"
```

### テストファイル一覧

| ファイル | テスト対象 |
|---------|----------|
| `BcCsvImportCore/tests/TestCase/Service/CsvImportServiceTest.php` | ヘッダ検証・バッチ読み込み・エンコーディング変換など |
| `BcCsvImportCore/tests/TestCase/Command/CleanupCommandTest.php` | cleanup コマンドの削除・dry-run・0件ケース |
| `BcCsvImportSampleProducts/tests/TestCase/Service/SampleProductsCsvImportServiceTest.php` | カラムマップ・buildEntity・テンプレートCSV |
| `BcCsvImportSampleOrders/tests/TestCase/Service/SampleOrdersCsvImportServiceTest.php` | カラムマップ・buildEntity・テンプレートCSV |
| `BcCsvImportSampleOrderDetails/tests/TestCase/Service/SampleOrderDetailsCsvImportServiceTest.php` | カラムマップ・1対多構造固有の検証 |
| `BcCsvImportBlogPosts/tests/TestCase/Service/BlogPostsCsvImportServiceTest.php` | カラムマップ・複合キー重複判定・カテゴリ/タグ解決 |
