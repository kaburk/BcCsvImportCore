# BcCsvImportCore 派生プラグイン作成ガイド

BcCsvImportCore をベースに独自テーブル向けのCSVインポートプラグインを作る際の手順・注意事項。

---

## 既存の派生プラグイン一覧

| プラグイン名 | 対象テーブル | 特徴 |
|-------------|------------|------|
| `BcCsvImportSampleProducts` | `bc_csv_sample_products` | 1行1レコードのシンプルな実装 |
| `BcCsvImportSampleOrders` | `bc_csv_sample_orders` | 1行1受注（単一テーブル） |
| `BcCsvImportBlogPosts` | `blog_posts`（bc-blog 管理） | 複合キー重複判定・jobMeta 活用・固有UI |
| `BcCsvImportSampleOrderDetails` | `bc_order_detail_orders` + `bc_order_detail_details` | 1対多（受注ヘッダー＋明細）の実装 |

---

## 最小構成（1テーブル・1行1レコード）

### 必要なファイル

```
MyImportPlugin/
├── composer.json          ← 実行時必須ではないが、配布・公開を考えるなら基本的に置く
├── config.php
├── config/
│   ├── setting.php
│   └── Migrations/
│       └── YYYYMMDDHHMMSS_CreateMyTable.php
├── src/
│   ├── MyImportPluginPlugin.php
│   ├── Controller/Admin/
│   │   └── MyImportsCsvImportsController.php
│   ├── Model/
│   │   ├── Entity/MyEntity.php
│   │   └── Table/MyTable.php
│   └── Service/
│       └── MyImportCsvImportService.php
└── templates/
    └── Admin/
        └── MyImportsCsvImports/  ← カスタムUIが不要ならディレクトリ自体不要
```

### コントローラー（カスタムUIなし・コアテンプレート使い回し）

```php
class MyImportsCsvImportsController extends CsvImportsController
{
    public function index(): void
    {
        parent::index();
        $this->set('pageTitle', __d('baser_core', 'マイインポート'));
        $this->set('adminBase', '/baser/admin/my-import-plugin/my_imports_csv_imports');
        $this->viewBuilder()->setPlugin('BcCsvImportCore');
        $this->viewBuilder()->setTemplatePath('Admin/CsvImports');
    }

    protected function createImportService(): CsvImportServiceInterface
    {
        return new MyImportCsvImportService();
    }
}
```

コアのテンプレートを使い回す場合はテンプレートファイル不要。  
独自UIが必要な場合（例: BlogPosts のブログ選択ドロップダウン）のみ独自テンプレートを作る。

### composer.json はいるのか

baserCMS の実行時ロードについては、`BaserCore\Utility\BcUtil::includePluginClass()` が `vendor/autoload.php` に対して
`addPsr4()` を実行するため、プラグイン内の `composer.json` がなくてもクラスロード自体は通る。

そのため、**ローカル実行だけを考えるなら必須ではない**。

ただし、次の理由から派生プラグインでは `composer.json` を残す前提を推奨する。

- Composer パッケージとして配布するときの `name` / `type` / `require` / `license` の定義元になる
- プラグイン単体で外部公開するときのメタ情報になる
- baserCMS 側の配布用処理・ドキュメントでもプラグイン単位の `composer.json` を前提にしている

整理すると次の通り。

| 用途 | プラグイン内 composer.json |
|------|----------------------------|
| ローカル実行だけ | なくても動く |
| サンプルとして repo に置く | あった方がよい |
| Composer パッケージとして配布する | 実質必要 |

### サービスクラス（最小実装）

`CsvImportService` を継承し、以下の4メソッドだけ実装する。

```php
class MyImportCsvImportService extends CsvImportService implements CsvImportServiceInterface
{
    public function getTableName(): string
    {
        return 'MyImportPlugin.MyTable';
    }

    public function getColumnMap(): array
    {
        return [
            'name'  => ['label' => '名前', 'required' => true, 'sample' => 'サンプル名'],
            'email' => ['label' => 'メール', 'required' => false, 'sample' => 'test@example.com'],
        ];
    }

    public function getDuplicateKey(): string
    {
        return 'email';  // 重複チェックに使うカラム名。不要なら ''
    }

    public function buildEntity(array $row): EntityInterface
    {
        $table = TableRegistry::getTableLocator()->get($this->getTableName());
        return $table->newEntity([
            'name'  => trim($row['name'] ?? '') ?: null,
            'email' => trim($row['email'] ?? '') ?: null,
        ]);
    }
}
```

---

## 1対多CSVの実装（BcCsvImportSampleOrderDetails パターン）

1行に親子両方のデータが入るCSVを、2テーブルに分けて保存するパターン。

### 設計方針

- `total` = CSV行数（明細行数）で通常通り管理する
- `getDuplicateKey()` は `''` を返す（コアの重複チェックをバイパス）
- `validateBatch()` と `processBatch()` を両方オーバーライドする
- `processBatch()` 内でバッチ行を `order_no` などの親キーでグループ化してから保存する

### processBatch() の核心ロジック

```php
// 1. バッチ内の行を親キーでグループ化
$groups = [];
foreach ($rows as $i => $row) {
    $parentKey = $row['order_no'];
    if (!isset($groups[$parentKey])) {
        $groups[$parentKey] = ['parentData' => [...], 'children' => []];
    }
    $groups[$parentKey]['children'][] = [...];
}

// 2. グループごとにトランザクション
$connection->begin();
foreach ($groups as $parentKey => $group) {
    // 親レコードを UPSERT（order_no で検索）
    $existing = $this->ordersTable->find()->where(['order_no' => $parentKey])->first();
    if ($existing) {
        // duplicate_mode に従って skip / overwrite / error
    } else {
        $parent = $this->ordersTable->newEntity($group['parentData']);
        $this->ordersTable->saveOrFail($parent);
    }

    // 子レコードを保存（order_id を紐付け）
    foreach ($group['children'] as $childData) {
        $childData['order_id'] = $parent->id;
        $child = $this->detailsTable->newEntity($childData);
        $this->detailsTable->save($child);
    }
}
$connection->commit();
```

### replace strategy の扱い

1対多では、子テーブルを先に削除してから親テーブルを削除する（外部キー制約がなくても順序を守る）。

```php
if ($job->import_strategy === 'replace' && !$job->target_cleared) {
    $this->detailsTable->deleteAll([]);  // 子を先に
    $this->ordersTable->deleteAll([]);   // 次に親
}
```

---

## jobMeta の活用（BcCsvImportBlogPosts パターン）

アップロード時のパラメータをジョブに紐付けて `buildEntity()` から参照する仕組み。

### アップロード時にパラメータを渡す方法

管理画面の hidden input または select の値をコントローラーで受け取り、`createJob()` の `meta` に渡す。

```php
// コントローラー側（CsvImportsController の upload アクション内）
$options = [
    'meta' => ['blog_content_id' => $this->request->getData('blog_content_id')],
    'mode' => $this->request->getData('mode'),
];
$this->importService->createJob($csvPath, $options);
```

### サービス側での参照

```php
public function buildEntity(array $row): EntityInterface
{
    $blogContentId = $this->getJobMeta('blog_content_id');
    // ...
}
```

---

## テンプレートの有無の判断基準

| ケース | テンプレート |
|--------|------------|
| コアと同じUIで良い（タイトル・adminBase のみ違う） | 不要。コントローラーで `viewBuilder` 指定 |
| 追加の入力項目がある（ドロップダウン等） | 独自テンプレートを作る |
| 完全にカスタムのUI | 独自テンプレートを作る |

コアのテンプレートは `BcCsvImportCore/templates/Admin/CsvImports/index.php`。  
`$pageTitle` と `$adminBase` を `??` で受け取るよう実装されている。

---

## GenerateTestCsvCommand の作成

`AbstractGenerateTestCsvCommand` を継承して6メソッドを実装する。

```php
class GenerateTestCsvCommand extends AbstractGenerateTestCsvCommand
{
    public static function defaultName(): string
    {
        return 'my_import_plugin.generate_test_csv';
    }

    protected function getCommandDescription(): string { return '説明'; }
    protected function getService(): CsvImportServiceInterface { return new MyImportCsvImportService(); }
    protected function getFilenamePrefix(): string { return 'import_my_'; }

    protected function buildRow(int $i, array $columnKeys): array
    {
        // $i は1始まりの通し番号
        $row = [];
        foreach ($columnKeys as $key) {
            $row[$key] = match ($key) {
                'name'  => 'テストデータ' . $i,
                'email' => 'test' . $i . '@example.com',
                default => '',
            };
        }
        return $row;
    }

    protected function getErrorPatterns(): array
    {
        return [
            '必須項目が空' => fn(array $row): array => array_merge($row, ['name' => '']),
        ];
    }
}
```

1対多CSVでは「1受注あたり複数明細」を連続行で出力するよう `buildRow()` を工夫する。  
`(int)(($i - 1) / 明細数) + 1` で受注番号インデックスを計算する。

---

## setting.php でのメニュー登録

複数インポートプラグインを同時有効化できるよう、メニューキーはプラグイン名にする。

```php
return [
    'BcApp' => [
        'adminNavigation' => [
            'Contents' => [
                'MyImportPlugin' => [  // ← 必ずプラグイン名をキーに
                    'title' => __d('baser_core', 'マイインポート'),
                    'url' => [
                        'Admin' => true,
                        'plugin' => 'MyImportPlugin',
                        'controller' => 'my_imports_csv_imports',
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
    'BcCsvImportCore' => [
        // 表示しない選択肢は false / 固定値は defaultXxx で指定
        'showImportStrategySelect' => false,
        'defaultImportStrategy'    => 'append',
    ],
];
```

---

## チェックリスト（派生プラグイン作成時）


- [ ] migration を作成した（既存テーブルを使う場合は不要）
- [ ] `config.php`（type / title / description）を作成した
- [ ] `config/setting.php` のメニューキーをプラグイン名にした
- [ ] コントローラーで `createImportService()` を実装した
- [ ] サービスで `getTableName / getColumnMap / getDuplicateKey / buildEntity` を実装した
- [ ] コアのテンプレートを使い回す場合は `viewBuilder()->setPlugin()` で指定した
- [ ] 管理画面からプラグインを有効化した
- [ ] `bin/cake plugin assets symlink` を実行した（再作成が必要な場合のみ、通常はプラグイン有効化で自動生成）
