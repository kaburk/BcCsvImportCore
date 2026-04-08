# BcCsvImportCore をベースに実アプリケーション用インポートを作る手順

この文書は、`BcCsvImportCore` をベースとして実案件向けのインポートプラグインを作成するための手順書です。
同梱プラグインとして `BcCsvImportSampleProducts`、`BcCsvImportSampleOrders`、`BcCsvImportSampleOrderDetails`、`BcCsvImportBlogPosts` が用意されています。

## 方針

実アプリケーション向けに作る方法は大きく 2 つあります。

### 1. 専用プラグイン化

BcCsvImportCore を共通基盤として利用しつつ、
管理画面名、コントローラー、テンプレート、サービスを専用プラグインとして切り出す方式です。
現在はこちらを推奨します。

複数のインポートプラグインを同時有効化することを考えると、
各プラグインが独自メニューと独自コントローラーを持つ構成が最も安全です。

### 2. 設定上書き方式

`config/setting.php` の `BcCsvImportCore` キーで共通設定だけを調整する方式です。
表示項目の固定やカテゴリ・タグの挙動制御など、画面や処理の細かな差分調整に向いています。

以下では、専用プラグイン化を前提に説明します。

## 手順

### 1. 取り込み先テーブルを用意する

- ※ ブログ記事のように既に別プラグインで取り込み先用意されている場合は不要です。

- 実際に保存したいテーブルの migration を作成する
- 主キー、重複キー、必須項目、日時カラムを整理する

例: 受注テーブルへ取り込みたい場合

```php
<?php
declare(strict_types=1);

use BaserCore\Database\Migration\BcMigration;

class CreateOrders extends BcMigration
{
    public function up()
    {
        $this->table('orders', ['collation' => 'utf8mb4_general_ci'])
            ->addColumn('order_no', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('customer_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('total_price', 'integer', ['null' => true, 'default' => null])
            ->addColumn('status', 'string', ['limit' => 30, 'null' => true, 'default' => 'new'])
            ->addColumn('ordered', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['order_no'], ['unique' => true])
            ->create();
    }
}
```

確認ポイント:

- 重複判定に使うキーは何か
- 上書き可能か
- 空文字と null の扱いをどうするか

この例では次のように決めています。

- 重複キー: `order_no`
- 上書き対象: `customer_name`, `total_price`, `status`, `ordered`
- 空文字: `total_price`, `ordered` は `null` に寄せる

### 2. Table / Entity を用意する

- Table クラスでバリデーションを定義する
- 必要なら Entity のアクセシブル設定を調整する

BcCsvImportCore の strict / lenient は、最終的にテーブル側のバリデーション結果を使うため、ここが基準になります。

例: `OrdersTable` で最低限のバリデーションを定義する

```php
<?php

namespace MyPlugin\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class OrdersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('orders');
        $this->setPrimaryKey('id');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('order_no')
            ->maxLength('order_no', 50)
            ->requirePresence('order_no', 'create')
            ->notEmptyString('order_no');

        $validator
            ->scalar('customer_name')
            ->maxLength('customer_name', 255)
            ->requirePresence('customer_name', 'create')
            ->notEmptyString('customer_name');

        $validator
            ->integer('total_price')
            ->greaterThanOrEqual('total_price', 0);

        $validator
            ->dateTime('ordered');

        return $validator;
    }
}
```

このバリデーションを基準にすると、例えば次の行は strict では登録停止、lenient ではエラー行としてスキップされます。

```csv
受注番号,顧客名,合計金額,状態,受注日時
,山田太郎,5000,new,2026-04-01 10:00:00
ORD-002,,3000,new,2026-04-01 10:10:00
ORD-003,佐藤花子,-100,new,2026-04-01 10:20:00
```

### 3. 実案件用 CsvImportService を作る

`CsvImportService` を継承し、最低限次を実装します。

```php
public function getTableName(): string;
public function getColumnMap(): array;
public function getDuplicateKey(): string;
public function buildEntity(array $row): EntityInterface;
```

例: 受注インポート用サービス

```php
<?php

namespace MyPlugin\Service;

use BcCsvImportCore\Service\CsvImportService;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Datasource\EntityInterface;
use Cake\ORM\TableRegistry;

class SampleOrdersCsvImportService extends CsvImportService implements CsvImportServiceInterface
{
    public function getTableName(): string
    {
        return 'MyPlugin.Orders';
    }

    public function getColumnMap(): array
    {
        return [
            'order_no' => [
                'label' => '受注番号',
                'required' => true,
                'sample' => 'ORD-0001',
            ],
            'customer_name' => [
                'label' => '顧客名',
                'required' => true,
                'sample' => '山田太郎',
            ],
            'total_price' => [
                'label' => '合計金額',
                'required' => false,
                'sample' => '5000',
            ],
            'status' => [
                'label' => '状態',
                'required' => false,
                'sample' => 'new',
            ],
            'ordered' => [
                'label' => '受注日時',
                'required' => false,
                'sample' => '2026-04-01 10:00:00',
            ],
        ];
    }

    public function getDuplicateKey(): string
    {
        return 'order_no';
    }

    public function buildEntity(array $row): EntityInterface
    {
        $table = TableRegistry::getTableLocator()->get($this->getTableName());

        $data = [
            'order_no' => trim((string)($row['order_no'] ?? '')),
            'customer_name' => trim((string)($row['customer_name'] ?? '')),
            'total_price' => $row['total_price'] !== '' ? (int)$row['total_price'] : null,
            'status' => $row['status'] ?: 'new',
            'ordered' => $row['ordered'] ?: null,
        ];

        return $table->newEntity($data);
    }
}
```

この例のポイント:

- CSV の見出しは業務担当者向けに `受注番号`, `顧客名` といった日本語ラベルにする
- 内部キーはテーブルのカラム名に合わせる
- `buildEntity()` で trim や数値変換を行う
- 空文字をそのまま保存したくない項目は `null` に寄せる

### 4. 専用コントローラーとメニューを用意する

複数のインポートプラグインを同時利用したい場合、
`BcCsvImportCore` のコントローラーをそのまま共有するのではなく、
各プラグイン側で専用コントローラーを作る構成にする。

例:

```php
<?php
declare(strict_types=1);

namespace MyPlugin\Controller\Admin;

use BcCsvImportCore\Controller\Admin\CsvImportsController;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use MyPlugin\Service\SampleOrdersCsvImportService;

class OrdersCsvImportsController extends CsvImportsController
{
    protected function createImportService(): CsvImportServiceInterface
    {
        return new SampleOrdersCsvImportService();
    }
}
```

`config/setting.php` では、`BcApp.adminNavigation` に独自キーでメニューを登録する。
これにより、他の CSV インポートプラグインとメニュー競合しない。

```php
<?php

return [
    'BcApp' => [
        'adminNavigation' => [
            'Contents' => [
                'MyOrdersCsvImport' => [
                    'title' => __d('baser_core', '受注CSVインポート サンプル'),
                    'url' => [
                        'Admin' => true,
                        'plugin' => 'MyPlugin',
                        'controller' => 'orders_csv_imports',
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
];
```

追加UIが不要なら、派生プラグイン側にテンプレートを複製せず、
コントローラーから `BcCsvImportCore` の共通テンプレートを指定して再利用できます。

例:

```php
public function index(): void
{
    parent::index();
    $this->set('pageTitle', __d('baser_core', '受注CSVインポート サンプル'));
    $this->set('adminBase', '/baser/admin/my-plugin/orders_csv_imports');
    $this->viewBuilder()->setTemplatePath($this->name);
    $this->viewBuilder()->setTemplate('BcCsvImportCore.Admin/CsvImports/index');
}
```

この方式なら、共通画面の修正を各派生プラグインへ個別反映する必要がありません。

ブログ選択のような追加入力が必要な場合は、`BcCsvImportBlogPosts` のように
専用テンプレートと `jobMeta` を組み合わせて実装する。

#### getTableName

- 対象 Table のエイリアスを返す

例:

```php
return 'MyPlugin.Orders';
```

同一プラグイン内なら `Orders` だけでも動くことがありますが、他プラグインとの衝突を避けるため、基本は `Plugin.Table` 形式が安全です。

#### getColumnMap

- CSV の列順ではなく、内部で扱うフィールド定義を返す
- `label` はテンプレートCSVやエラー表示に使われる
- `sample` はテンプレートCSVのサンプル値に使われる

例:

```php
return [
    'order_no' => ['label' => '受注番号', 'required' => true, 'sample' => 'ORD-0001'],
    'customer_name' => ['label' => '顧客名', 'required' => true, 'sample' => '山田太郎'],
    'total_price' => ['label' => '合計金額', 'required' => false, 'sample' => '5000'],
];
```

この定義から生成されるテンプレートCSVのイメージ:

```csv
受注番号,顧客名,合計金額
ORD-0001,山田太郎,5000
```

#### getDuplicateKey

- 重複判定のキーを返す
- 重複判定を使わない場合は空文字を返す設計も検討できる

例:

```php
return 'order_no';
```

実務では次の決め方が多いです。

- 会員: `email`
- 商品: `sku`
- 受注: `order_no`
- 顧客コード連携: `customer_code`

#### buildEntity

- CSV 値を DB 向けの型に変換する
- `int`, `bool`, `datetime` などの整形をここで行う
- Table のバリデーションに通る形へ寄せる

変換の考え方:

- 数値: `''` は `null`、値があるときだけ `(int)` 変換
- 真偽値: `1`, `0`, `true`, `false`, `公開`, `非公開` のような業務表現をどこまで許容するか先に決める
- 日付: DB に渡す前に `Y-m-d H:i:s` へ揃えるか、Table 側で受ける形式に合わせる

例: 公開フラグを業務CSV向けに寄せる

```php
'is_published' => in_array((string)($row['is_published'] ?? ''), ['1', 'true', '公開'], true),
```

### 4. 設定を差し替える

#### UI 表示制御（派生プラグインの `config/setting.php`）

UI表示設定（`showXxx` / `defaultXxx`）は `BcCsvImportCore.*` ではなく、
**自プラグインのキー**で `config/setting.php` に記述してください。

複数の派生プラグインを同時有効化する場合、`BcCsvImportCore.*` を上書きし合うとロード順によって設定が踏みにじられるためです。

`CsvImportsController::resolveUiSettings()` は自プラグインのキーを優先し、未設定の場合は `BcCsvImportCore.*` にフォールバックします。

```php
// MyPlugin/config/setting.php
return [
    'BcApp' => [ /* メニュー登録 */ ],
    'MyPlugin' => [
        // UI を非表示にし、常に strict + append + skip で動作させる例
        'showOptionSection'    => false,   // オプションアコーディオン自体を非表示
        'defaultMode'          => 'strict',
        'defaultImportStrategy'=> 'append',
        'defaultDuplicateMode' => 'skip',
    ],
];
```

横断の別プラグイン（`AnotherPlugin`）が別項目を固定していても競合しない。

```php
// AnotherPlugin/config/setting.php
return [
    'AnotherPlugin' => [
        'showModeSelect'          => false,
        'defaultMode'             => 'lenient',
        'showImportStrategySelect'=> false,
        'defaultImportStrategy'   => 'replace',
    ],
];
```

`showOptionSection` を `false` にするとオプションアコーディオン全体が非表示になりますが、
各 `defaultXxx` の値は hidden input として送信されるため処理は正常に動作します。

項目ごとに個別に非表示にしたい場合は `showOptionSection` を `true` のまま、各 `showXxx` を `false` にします。

#### BcCsvImportCore 全体のプロジェクト共通設定（`setting_customize.php`）

`batchSize` や `csvExpireDays` などプロジェクト全体に共通の設定は、`BcCsvImportCore/config/setting_customize.php` で上書きします。

```php
$customize_config = [
    'BcCsvImportCore' => [
        'csvExpireDays' => 7,    // ファイル保持日数を延長
        'batchSize'     => 500,  // 1バッチの処理件数を減らす
    ],
];
```

> **注意:** `showXxx` / `defaultXxx` を `BcCsvImportCore.*` で設定しても、派生プラグイン側に同名キーがあればそちらが優先されます。
> `BcCsvImportCore` のテンプレートを直接使う場合（派生プラグインのキーなし）は Core 側の設定がそのまま使われます。

### 5. テンプレートCSVを確認する

`getColumnMap()` の `label` と `sample` に基づいてテンプレートCSVが生成されます。
運用担当者が迷わない列名になっているかを確認します。

確認観点:

- CSV の見出しが業務用語になっているか
- 必須列が分かりやすいか
- 日付形式のサンプルが実運用に合っているか
- 真偽値の表現が利用者に伝わるか

悪い例:

```csv
order_no,customer_name,total_price,is_published
```

業務担当者に内部カラム名をそのまま見せると分かりにくいことがあります。

改善例:

```csv
受注番号,顧客名,合計金額,公開フラグ（1/0）
```

### 6. 重複処理を確認する

`duplicate_mode` の各動作を確認します。

- `skip`
- `overwrite`
- `error`

実業務で `overwrite` を許容するかは先に決めておく必要があります。

判断の目安:

- `skip`: 既存値を壊したくないマスタ系に向く
- `overwrite`: 外部基幹からの再取り込みに向く
- `error`: 重複自体を業務ミスとして扱いたい場合に向く

例: 既存データに `ORD-0001` がある場合

```csv
受注番号,顧客名,合計金額
ORD-0001,山田太郎,6000
```

- `skip`: 登録しない。既存レコードはそのまま
- `overwrite`: `ORD-0001` の内容を更新する
- `error`: エラー一覧とエラーCSVに出す

### 7. replace の安全性を確認する

`replace` を許可する場合は以下を確認します。

- 対象テーブルを全削除して問題ないか
- 関連テーブルや外部キーへの影響がないか
- strict / lenient のどちらを運用で許可するか

`replace` を安易に許可しない方がよいケース:

- 受注や会員のように履歴保持が重要なデータ
- 他テーブルから参照されているデータ
- 本番運用で誤アップロードの影響が大きいデータ

逆に `replace` が向くケース:

- 外部システムの最新マスタを毎回丸ごと同期する
- 商品在庫や店舗一覧など、全件入れ替え前提のデータ

### 8. 実データに近い CSV でテストする

少なくとも次を確認します。

- 正常系
- 必須項目欠落
- 型不正
- 重複データ
- 空行混入
- replace 実行時の確認ダイアログ
- strict 失敗時の failed ジョブ表示

追加で確認しておくとよい項目:

- Shift-JIS の CSV が混ざる運用か
- 同一 CSV 内に重複キーが複数ある場合の扱い
- 1万件、10万件など件数増加時の所要時間
- エラーCSVの内容が業務担当に理解できるか

テスト用に、最初は 10 件程度の小さな CSV を手で確認し、その後に 1,000 件以上で性能を見ると安全です。

例: 最初の確認に使う小さな CSV

```csv
受注番号,顧客名,合計金額,状態,受注日時
ORD-0001,山田太郎,5000,new,2026-04-01 10:00:00
ORD-0002,佐藤花子,3000,new,2026-04-01 10:10:00
ORD-0002,佐藤花子,3000,new,2026-04-01 10:10:00
,鈴木一郎,4000,new,2026-04-01 10:20:00
```

この CSV なら、重複と必須欠落の両方をすぐ確認できます。

## サンプル実装から差し替える主な箇所

- `BcCsvImportSampleProducts\Service\SampleProductsCsvImportService`（`BcCsvImportSampleProducts` プラグイン内）
- サンプルテーブル migration（`BcCsvImportSampleProducts/config/Migrations/`）
- サンプル Table / Entity（`BcCsvImportSampleProducts/src/Model/`）
- 1対多サンプル: `BcCsvImportSampleOrderDetails`（`bc_csv_sample_order_headers` / `bc_csv_sample_order_details`）
- 差し替えプラグインの `config/setting.php` で `importServiceClass` を指定

## 専用プラグイン化する場合の追加作業

- プラグイン名と管理メニュー名の変更
- `config.php` のタイトル・説明更新
- `permission.php` の権限名更新
- 必要なら管理画面 URL や controller 名を専用化

## 実務上の推奨

- まずは BcCsvImportCore の管理画面をそのまま使い、サービス差し替えで導入する
- 実績が固まってから専用プラグイン化を検討する
- unreleased の間だけ migration やサンプルコードを整理する

## 最小構成のまとめ

まず動かすだけなら、最低限必要なのは次の 4 点です。

1. 取り込み先テーブル migration
2. Table のバリデーション
3. `CsvImportService` 継承クラス
4. 専用コントローラー（`createImportService()` をオーバーライドしてサービスを返す）

この4点が揃えば、管理画面やジョブ機構は BcCsvImportCore をそのまま流用できます。

UI 表示の制御（`showXxx` / `defaultXxx`）は、自プラグインの `config/setting.php` の  
**自プラグイン名キー**（例: `MyPlugin.*`）に追記するだけです。`BcCsvImportCore.*` は上書きしないでください。

## テストCSV生成コマンドの付け方

サンプルプラグインと同様に、`AbstractGenerateTestCsvCommand` を継承すると `getColumnMap()` に一致したテストCSVを簡単に生成できます。

共通仕様:

```bash
bin/cake MyPlugin.generate_test_csv
bin/cake MyPlugin.generate_test_csv --sizes=10k,100k --errors=5
```

- `--output`: 出力先ディレクトリ
- `--sizes`: 生成件数。デフォルトは `10k`
- `--errors`: エラー行の割合（%）。デフォルトは `0`
- 出力CSVは UTF-8 BOM 付き

ファイル名プレフィックスは、対象がすぐ分かる名前にしておくと運用しやすいです。
例: `import_products_`, `import_orders_`, `import_sample_order_details_`
