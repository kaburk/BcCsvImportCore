# Plan: BcCsvImportCore プラグイン仕様

baserCMS5 管理画面から CSV 一括インポートを行う汎用プラグイン。  
AJAX 分割処理、ジョブテーブルによる中断・再開、抽象基底サービスによる再利用を前提とする。  
現時点ではサンプル実装として商品テーブル向けインポートを同梱している。

---

## 概要

- プラグイン名: `BcCsvImportCore`
- 対象: baserCMS5 管理画面
- 主用途:
    - レンタルサーバでも扱える大量 CSV インポート
    - 途中中断 / 再開 / キャンセル / 削除が可能なジョブ方式
    - 実アプリケーション用 CSV インポートプラグインの基底実装
- サンプル実装:
    - サービス: `BcCsvImportCore\Service\SampleProductsCsvImportService`
    - テーブル: `bc_csv_sample_products`

---

## 現在の確定仕様

| 項目 | 決定内容 |
|------|----------|
| フォルダ名 | `BcCsvImportCore` |
| サンプル対象 | 商品情報（`name / sku / price / stock / category / description / status`） |
| CSV文字コード | 自動判別（`mb_detect_encoding`）デフォルト + 手動選択（UTF-8 / Shift-JIS） |
| バリデーションモード | `strict`（事前確認）/ `lenient`（スキップ） |
| strict の流れ | 1パス目で全件バリデーション → エラー 0 件なら 2 パス目で登録 |
| 重複処理 | `skip` / `overwrite` / `error` |
| インポート方式 | `append` / `replace` |
| replace の動作 | 登録直前に対象テーブルを全削除してから取込 |
| replace の削除方式 | `TRUNCATE` 優先、失敗時 `DELETE` 全件削除へフォールバック |
| バッチ方式 | AJAX 分割 + ジョブテーブル再開方式 |
| 一時ファイル削除 | N日後自動削除（デフォルト 3 日） |
| テンプレートCSV | ラベル行 + サンプル行を動的生成 |
| エラー出力 | 画面表示 + エラーCSVダウンロード |
| エラー保存方式 | DB の巨大 JSON ではなく JSON Lines ファイル保存 |
| ログ出力 | `csv_import` ログチャネル |
| 拡張方式 | `CsvImportService` 抽象基底クラス継承 |
| 設定上書き | `config/setting_customize.php` で上書き可能 |
| 空行の扱い | 空行は処理対象・総件数の双方から除外する |

---

## 管理画面フロー

### 1. CSVアップロード画面

画面: `Admin/CsvImports/index`

- CSVファイル選択
- 文字コード選択
- バリデーションモード選択
- インポート方式選択
    - `append`: 既存データを残したまま追記 / 更新
    - `replace`: 登録直前に既存データを全削除してから取込
- 重複データ処理選択
- テンプレートCSVダウンロード
- アップロード開始ボタン
- 未完了ジョブ一覧
    - 状態表示
    - エラー概要プレビュー
    - 再開ボタン
    - エラーCSVダウンロード
    - ジョブ削除ボタン

### 2. 処理中

- strict:
    - `検証中 N / 総件数`
    - エラー 0 件なら `登録中 N / 総件数`
- lenient:
    - そのまま `登録中 N / 総件数`
- 進捗バー
- 件数表示（3桁カンマ区切り + 進捗%）
- キャンセルボタン
- 処理中カーソル表示

### 3. 結果表示

- 処理件数
- 成功件数
- 重複スキップ件数（ある場合のみ表示）
- エラー件数（ある場合のみ表示）
- 処理時間（時分秒表記）
- エラー一覧（行番号 / フィールド / エラー内容）
- エラーCSVダウンロード

---

## strict / lenient の詳細

### strict

```text
1. CSVアップロード
2. validate_batch を繰り返し実行
3. エラー件数が 0 件なら process_batch へ進む
4. replace モードなら process_batch 開始直前に対象テーブルを全削除
5. process_batch を最後まで実行
```

特徴:

- 検証エラーが 1 件でもあれば登録しない
- 検証エラーがあるジョブは `failed` で保持し、未完了一覧に残す
- `failed` ジョブは再開不可、エラーCSV確認と削除のみ可能
- `replace` でも、検証エラー時は既存データを削除しない

### lenient

```text
1. CSVアップロード
2. そのまま process_batch を繰り返し実行
3. エラー行はスキップしながら登録を継続
4. replace モードなら最初の process_batch 直前に対象テーブルを全削除
```

特徴:

- バリデーションエラーや重複エラーをスキップしながら登録を進める
- 結果画面では、エラー行と重複スキップを分けて表示する

---

## インポート方式の詳細

### append

- 既存データを削除しない
- 重複時の挙動は `duplicate_mode` に従う

### replace

- 登録開始直前に対象テーブルの既存データを全削除する
- 削除はジョブ単位で一度だけ実行する
- 再開時に二重削除しないよう `target_cleared` で管理する
- 削除処理は以下の順:
    1. `TRUNCATE`
    2. 失敗時は `DELETE` 全件削除へフォールバック

### 管理画面上の注意

- `replace` 選択時は確認ダイアログを表示する
- メッセージには以下を含める
    - 既存データが削除される
    - 元に戻せない
    - strict では検証エラー時に削除されない

---

## 性能改善方針と現在の実装

100万件規模を想定し、以下の問題に対応済み。

### 1. CSV を毎回先頭から読み直さない

旧方式:

- `offset` 指定ごとに CSV を先頭から開き直し、毎回読み飛ばしていた

現方式:

- ジョブごとに以下を保持する
    - `validate_position`
    - `import_position`
- `ftell()` / `fseek()` ベースで続き位置から読み込む

### 2. 空行を処理件数に含めない

- `countCsvRows()` と `readCsvBatchByPosition()` の双方で空行を除外する
- 総件数と実処理件数がずれにくい

### 3. 重複確認の N 回クエリを避ける

旧方式:

- 1行ごとに `find()->where()->first()`

現方式:

- バッチ内の重複キーを先に集めて一括検索
- メモリ上の map で既存データを参照

### 4. エラーログを巨大 JSON として DB に保持しない

- `error_log_path` に JSON Lines 形式で追記
- エラーCSV生成時のみファイルを読み込む
- 未完了ジョブ一覧では先頭数件だけプレビュー表示する

### 5. 将来の高速化候補

- bulk insert / bulk upsert の導入
- overwrite モード向けの DB 方言別 upsert 最適化
- エラーCSV生成のストリーミング化

---

## DB設計

### `bc_csv_sample_products`

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

### `bc_csv_import_jobs`

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int PK AUTO | |
| job_token | varchar(255) | ジョブ識別子 |
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
| csv_path | varchar(255) | 一時CSVファイル |
| validate_position | bigint | validate 読込位置 |
| import_position | bigint | import 読込位置 |
| target_cleared | boolean | replace で全削除済みか |
| error_log_path | varchar(255) | エラーログファイルパス |
| expires_at | datetime | 期限 |
| started_at | datetime | 開始日時 |
| ended_at | datetime | 終了日時 |
| created | datetime | |
| modified | datetime | |

---

## Migration 構成

現時点の初期構成は次の 2 本。

- `20260402000001_CreateBcCsvImportJobs.php`
- `20260402000002_CreateBcCsvSampleProducts.php`

補足:

- ジョブテーブル用 migration は未リリース前提で 1 本に統合済み
- `schema-dump-default.lock` は生成物であり、Git 管理対象外

---

## エンドポイント設計

管理画面内 AJAX は API ルートではなく Admin ルートを使う。

| Method | URL | 役割 |
|--------|-----|------|
| POST | `/baser/admin/bc-csv-import-core/csv_imports/upload` | CSVアップロード + ジョブ作成 |
| POST | `/baser/admin/bc-csv-import-core/csv_imports/validate_batch` | strict 1パス目の検証 |
| POST | `/baser/admin/bc-csv-import-core/csv_imports/process_batch` | 登録処理 |
| GET / POST | `/baser/admin/bc-csv-import-core/csv_imports/status/{token}` | 進捗取得 |
| POST | `/baser/admin/bc-csv-import-core/csv_imports/cancel/{token}` | キャンセル |
| POST | `/baser/admin/bc-csv-import-core/csv_imports/delete/{token}` | ジョブ削除 |
| GET | `/baser/admin/bc-csv-import-core/csv_imports/download_template` | テンプレートCSV |
| GET | `/baser/admin/bc-csv-import-core/csv_imports/download_errors/{token}` | エラーCSV |

### 管理画面AJAXの注意点

- `X-CSRF-Token` ヘッダーが必要
- `FormProtection` 対応のため `unlockedActions` 登録が必要
- `validate_batch` / `process_batch` / `download_errors` など snake_case の命名で統一する
- `permission.php` に AJAX とダウンロード系の権限定義を入れる

---

## 設定方式

`config/setting.php` はデフォルト設定、環境差分は `config/setting_customize.php` で上書きする。

### 主な設定値

| キー | 例 | 説明 |
|------|----|------|
| `importServiceClass` | `BcCsvImportCore\\Service\\SampleProductsCsvImportService` | 具象サービス差し替え |
| `csvExpireDays` | `3` | 一時ファイル保持日数 |
| `batchSize` | `1000` | バッチ件数 |
| `showEncodingSelect` | `true` | 文字コード選択の表示 |
| `showModeSelect` | `true` | バリデーションモード選択の表示 |
| `showImportStrategySelect` | `true` | インポート方式選択の表示 |
| `showDuplicateModeSelect` | `true` | 重複処理選択の表示 |
| `defaultEncoding` | `auto` | 初期文字コード |
| `defaultMode` | `strict` | 初期バリデーションモード |
| `defaultImportStrategy` | `append` | 初期インポート方式 |
| `defaultDuplicateMode` | `skip` | 初期重複処理 |

### GUI固定化

- `showXxx = false` にすると選択 UI を非表示にし、`defaultXxx` を固定値として使う
- `setting_customize.php.default` を雛形として配布し、実ファイルは Git 管理しない

---

## 管理画面 View 方針

管理画面テンプレートは `plugins/bc-admin-third` に合わせる。

### 原則

- `section.bca-section`
- `bca-main__heading`
- `form-table bca-form-table`
- `bca-form-table__label` / `bca-form-table__input`
- `bca-actions` / `bca-actions__main` / `bca-actions__item`
- 未完了一覧は `bca-table-listup`

独自 HTML 構造を増やしすぎず、管理画面デザインが自然に当たる構造を優先する。

---

## テストデータ生成スクリプト

同梱スクリプト:

- `plugins/BcCsvImportCore/bin/generate_test_csv.php`

### 機能

- `1k`, `10k`, `100k`, `500k`, `1m` など任意サイズ対応
- `--errors=5` のようにエラー行混入率指定可能
- 商品名空欄 / 負数価格 / 文字列価格 / 不正公開フラグ / 重複SKU を混入可能

### 例

```bash
php plugins/BcCsvImportCore/bin/generate_test_csv.php --sizes=100k --errors=5
```

---

## 拡張方式

専用プラグインや専用サービスは `CsvImportService` を継承して以下を実装する。

```php
abstract public function getTableName(): string;
abstract public function getColumnMap(): array;
abstract public function getDuplicateKey(): string;
abstract public function buildEntity(array $row): EntityInterface;
```

補足:

- `getColumnMap()` の `label` はテンプレートCSV、エラー表示、エラーCSV出力で使われる
- `getColumnMap()` の `sample` はテンプレートCSVのサンプル行に使われる
- `buildEntity()` では CSV 値の型変換とテーブル側バリデーションに合わせた整形を行う

必要に応じて以下も将来の拡張ポイントになりうる。

- 全件削除の方法をサービスごとに上書き
- 独自バリデーション
- 独自のエラー整形
- バルク insert / bulk upsert の差し替え

---

## 既知の注意点

### 1. replace は破壊的操作

- 必ず確認ダイアログを出す
- strict では検証エラー時に削除されない
- lenient では登録開始時に削除される

### 2. DB差異

- MySQL は `TEXT` 上限に注意
- PostgreSQL / SQLite は `text` の扱いが比較的大きい
- `TRUNCATE` の挙動差異があるため、失敗時の `DELETE` フォールバックを持つ

### 3. 管理画面AJAX

- CSRF / FormProtection 対応が必要
- Admin ルートを使う

### 4. 生成物の扱い

- `config/setting_customize.php` は `.gitignore` 対象
- `config/Migrations/schema-dump-default.lock` は `.gitignore` 対象

---

## 関連ドキュメント

- `.github/prompts/MakePluginsAndThemes/basercms-admin-ajax.prompt.md`
- `.github/prompts/MakePluginsAndThemes/basercms-plugin-setting-customize.prompt.md`
- `.github/prompts/MakePluginsAndThemes/basercms-database-notes.prompt.md`
- `.github/prompts/MakePluginsAndThemes/basercms-admin-view.prompt.md`
- `.github/prompts/MakePluginsAndThemes/basercms-plugin-release-docs-and-migrations.prompt.md`

---

## 将来対応候補

- ジョブ履歴一覧画面
- ログ画面閲覧
- エラーCSV生成のストリーミング化
- バルク insert / bulk upsert による更なる高速化
- サービスごとの削除戦略オーバーライド
