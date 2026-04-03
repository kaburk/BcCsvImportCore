<?php

$viewFilesPath = str_replace(ROOT, '', WWW_ROOT) . 'files';
return [
    'type' => 'Plugin',
    'title' => __d('baser_core', 'CSVインポート'),
    'description' => __d('baser_core', '管理画面からCSVで一括登録できる汎用インポートプラグインです。'),
    'author' => 'kaburk',
    'url' => 'https://blog.kaburk.com/',
];
