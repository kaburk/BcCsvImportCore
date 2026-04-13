# BcCsvImportCore ドキュメントガイド

`BcCsvImportCore` 関連ドキュメントの入口です。  
重複を避けるため、詳細仕様・作成手順・早見表の役割を分けています。

---

## どの文書を見るか

| 文書 | 用途 |
|------|------|
| `specification.md` | 正式な詳細仕様。動作仕様、設定、DB設計、ログ、管理画面、権限、テストをまとめて確認したいときに使う |
| `create-custom-import-plugin.md` | 新規派生プラグインの詳細手順。実案件向けプラグインを1本作るときの作業順で読む |
| `derived-plugin.md` | 派生プラグインの早見表。拡張ポイント、1対多、jobMeta、permission、設定キーを短く確認したいときに使う |

---

## 現在の要点

| 項目 | 内容 |
|------|------|
| コアの役割 | CSVインポートの共通基盤。AJAX分割処理、ジョブ管理、再開、エラーCSV出力を提供する |
| 主な選択肢 | `strict / lenient`、`append / replace`、`skip / overwrite / error` |
| 一時ファイル管理 | ジョブごとにCSVとエラーログを保持し、`BcCsvImportCore.cleanup` で掃除できる |
| 派生プラグインの基本 | 専用コントローラー、専用メニュー、専用 `config/permission.php` を持たせる |
| UI設定 | `BcCsvImportCore.*` ではなく `自プラグイン名.*` を優先して使う |
| テスト | Core / SampleOrders / SampleOrderDetails / SampleProducts / BlogPosts の PHPUnit スイートを定義済み |

---

## 同梱の派生プラグイン

| プラグイン | 用途 |
|-----------|------|
| `BcCsvImportSampleProducts` | 1テーブル・1行1レコードの最小サンプル |
| `BcCsvImportSampleOrders` | 単一テーブルの受注サンプル |
| `BcCsvImportSampleOrderDetails` | 1対多の受注ヘッダー＋明細サンプル |
| `BcCsvImportBlogPosts` | `bc-blog` 向けの実践サンプル。複合キー重複判定、jobMeta、追加UIあり |

---

## 運用の最小メモ

### cleanup コマンド

```bash
docker compose exec bc-php bash -c "cd /var/www/html && bin/cake BcCsvImportCore.cleanup"
docker compose exec bc-php bash -c "cd /var/www/html && bin/cake BcCsvImportCore.cleanup --dry-run"
```

- cleanup は `BcCsvImportCore.cleanup` に一元化される
- 派生プラグインごとの `*.cleanup` コマンドは持たない

### 権限定義

- Core と全派生プラグインで `config/permission.php` を持つ
- BlogPosts だけは `download_posts` 分を含めて 10 アクション

### テスト実行

```bash
docker compose exec bc-php bash -c \
  "cd /var/www/html && vendor/bin/phpunit \
    --testsuite BcCsvImportCore \
    --testsuite BcCsvImportSampleOrders \
    --testsuite BcCsvImportSampleOrderDetails \
    --testsuite BcCsvImportSampleProducts \
    --testsuite BcCsvImportBlogPosts \
    --no-coverage"
```

---

## 編集方針

- 仕様変更時はまず `specification.md` を更新する
- 実装手順が変わった場合だけ `create-custom-import-plugin.md` を更新する
- 派生プラグインの共通パターンが変わった場合だけ `derived-plugin.md` を更新する
- この文書は索引と要約に留め、詳細を重ね書きしない
