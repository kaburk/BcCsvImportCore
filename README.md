# BcCsvImportCore

BcCsvImportCore は、baserCMS5 管理画面から CSV を分割インポートできる汎用プラグインです。
大量データの取り込み、中断・再開可能なジョブ管理、strict / lenient の2モード、エラーCSV出力を提供します。

## 主な機能

- CSV アップロードと管理画面からの一括登録
- strict / lenient のバリデーションモード
- append / replace のインポート方式
- 重複時の `skip` / `overwrite` / `error` 処理
- 中断・再開・削除が可能なジョブ管理
- エラー一覧表示とエラーCSVダウンロード
- オプション設定のアコーディオン開閉 UI
- 検証中・登録中でプログレスバーの色が切り替わる
- アップロード時の CSV ヘッダー不一致検出（正しいヘッダ行と実際のヘッダ行を並べて表示）
- `logs/csv_import.log` へのイベントログ出力
- インポートサービスクラスを差し替えるだけで任意のテーブルに対応

## 導入概要

`BcCsvImportCore` は**単体では動作しません**。必ず以下のようなプラグインと併用してください。

| プラグイン | 用途 |
|---|---|
| [`BcCsvImportSampleProducts`](https://github.com/kaburk/BcCsvImportSampleProducts) | 動作確認・開発学習用サンプル |
| [`BcCsvImportSampleOrders`](https://github.com/kaburk/BcCsvImportSampleOrders) | 受注データインポートのサンプル実装 |
| [`BcCsvImportSampleOrderDetails`](https://github.com/kaburk/BcCsvImportSampleOrderDetails) | 1対多の受注ヘッダー・受注明細インポートのサンプル実装 |
| [`BcCsvImportBlogPosts`](https://github.com/kaburk/BcCsvImportBlogPosts) | ブログ記事インポート（実際にブログ記事移行とかで使えます） |

**最小構成での導入手順（BcCsvImportSampleProducts を使う場合）**

1. `BcCsvImportCore` を有効化する（jobs テーブルが作成される）
2. `BcCsvImportSampleProducts` を有効化する（サンプル商品テーブルが作成される）
3. 管理画面の「商品CSVインポート サンプル」メニューから利用する

**複数プラグインを同時利用する場合**

- `BcCsvImportSampleProducts`
- `BcCsvImportSampleOrders`
- `BcCsvImportSampleOrderDetails`

複数同時に有効化できます。
各プラグインが独自の管理画面コントローラーと独自メニューを持つため、サービスクラスやメニュー表示が競合しません。

## ドキュメント

- 詳細仕様: [docs/specification.md](docs/specification.md)
- 実アプリケーション向けの作成手順: [docs/create-custom-import-plugin.md](docs/create-custom-import-plugin.md)

## 画面の見方

派生プラグインの多くは、管理画面で `BcCsvImportCore` の共通画面を再利用します。

### 1. 画面上部の操作エリア

- `テンプレートCSVダウンロード`: `getColumnMap()` の `label` / `sample` からその場で生成したテンプレートを取得する
- `CSVファイル選択`: アップロード対象ファイルを選ぶ
- `アップロード`: ジョブを作成し、バリデーションまたはインポートを開始する

### 2. オプションエリア

アコーディオン内に次の設定があります。

- 文字コード
- バリデーションモード（strict / lenient）
- インポート方式（append / replace）
- 重複データの処理（skip / overwrite / error）

派生プラグインによっては、この一部または全部を非表示にして固定動作させます。

### 3. 未完了ジョブ一覧

`pending` / `processing` / `failed` のジョブを表示します。
進捗バー、再開ボタン、エラーCSVダウンロード、削除ボタンをここで確認します。

### 4. 最近の履歴

`completed` / `cancelled` を含む最近のジョブを表示します。
エラーが残っているジョブは、ここからもエラーCSVをダウンロードできます。

## テストCSV生成コマンド

各同梱プラグインには `GenerateTestCsvCommand` があり、`getColumnMap()` に一致したテストCSVを生成できます。
共通仕様は次の通りです。

```bash
bin/cake <PluginName>.generate_test_csv
bin/cake <PluginName>.generate_test_csv --sizes=10k,100k --errors=5
```

- `--output`: 出力先ディレクトリ（デフォルト: `tmp/csv/`）
- `--sizes`: 生成件数。デフォルトは `10k`
- `--errors`: エラー行の割合（%）。デフォルトは `0`
- 生成CSVは UTF-8 BOM 付きで出力される

`BcCsvImportSampleOrderDetails` だけは、`--sizes` が「受注件数」ではなく「明細行数」を意味します。

## 実装方針

- 共通のジョブ管理・分割処理・テンプレートCSV生成は `BcCsvImportCore` が担当
- 各派生プラグインは対象テーブルごとの `CsvImportService` を実装
- 管理画面名や追加UIが必要な場合は、派生プラグイン側で専用コントローラーとテンプレートを持つ
- 追加UIが不要な派生プラグインは、専用コントローラーから `BcCsvImportCore.Admin/CsvImports/index` を指定して共通テンプレートを再利用できる
- `jobMeta` を使うと、アップロード時の追加パラメータを `buildEntity()` まで引き渡せる

`BcCsvImportBlogPosts` では、この仕組みを使って `blog_content_id` を受け渡し、
ブログ選択付きのインポート画面を実現しています。
独自にインポート機能を作成するときの参考になるのではないかと思います。

## ライセンス

MIT License. 詳細は [LICENSE.txt](LICENSE.txt) を参照してください。
