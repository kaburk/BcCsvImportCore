<?php

/**
 * アクセスルール初期値
 */
return [
    'permission' => [

        'CsvImportsAdmin' => [
            'title' => __d('baser_core', 'CSVインポート管理'),
            'plugin' => 'BcCsvImportCore',
            'type' => 'Admin',
            'items' => [
                'Index' => [
                    'title' => __d('baser_core', 'CSVアップロード画面'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/index',
                    'method' => 'GET',
                    'auth' => true,
                ],
                'Upload' => [
                    'title' => __d('baser_core', 'CSVアップロード'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/upload',
                    'method' => 'POST',
                    'auth' => true,
                ],
                'ValidateBatch' => [
                    'title' => __d('baser_core', 'バリデーションバッチ'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/validate_batch',
                    'method' => 'POST',
                    'auth' => true,
                ],
                'ProcessBatch' => [
                    'title' => __d('baser_core', '登録バッチ'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/process_batch',
                    'method' => 'POST',
                    'auth' => true,
                ],
                'Status' => [
                    'title' => __d('baser_core', '進捗確認'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/status/*',
                    'method' => 'GET',
                    'auth' => true,
                ],
                'Cancel' => [
                    'title' => __d('baser_core', 'キャンセル'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/cancel/*',
                    'method' => 'POST',
                    'auth' => true,
                ],
                'Delete' => [
                    'title' => __d('baser_core', 'ジョブ削除'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/delete/*',
                    'method' => 'POST',
                    'auth' => true,
                ],
                'DownloadTemplate' => [
                    'title' => __d('baser_core', 'テンプレートCSVダウンロード'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/download_template',
                    'method' => 'GET',
                    'auth' => true,
                ],
                'DownloadErrors' => [
                    'title' => __d('baser_core', 'エラーCSVダウンロード'),
                    'url' => '/baser/admin/bc-csv-import-core/csv_imports/download_errors/*',
                    'method' => 'GET',
                    'auth' => true,
                ],
            ],
        ],

    ],
];
