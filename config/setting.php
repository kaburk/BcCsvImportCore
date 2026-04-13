<?php

use Cake\Utility\Hash;

$config = [
    /**
     * BcCsvImportCore 設定
     */
    'BcCsvImportCore' => [
        // インポートサービスクラス
        // BcCsvImportSampleProducts など、独自プラグインの setting.php で差し替える。
        // BcCsvImportCore 単体では null（未設定）のため、必ず差し替えプラグインを有効化すること。
        'importServiceClass' => null,

        // 一時 CSV ファイル・ジョブレコードの保持日数
        // この日数が経過したジョブは cleanupExpiredJobs() で削除される
        // cleanupExpiredJobs() の定期実行（cron 等）は未実装
        'csvExpireDays' => 3,

        // AJAX バッチ処理の 1 回あたりの処理件数
        // 大きくすると 1 リクエストあたりの処理が増えるが、タイムアウトリスクも上がる
        // レンタルサーバなど低スペック環境では小さくする（例: 100〜500）
        'batchSize' => 1000,

        // 文字コード自動判別で読み込むサンプルバイト数
        // 大きくするほど検出精度が上がるが、巨大 CSV の先頭読み込みコストが増える
        'encodingSniffBytes' => 8192,

        // --- GUI表示設定 ---
        // true にするとインポート画面で利用者が選択できる。
        // false にすると選択UIを非表示にし、下記の defaultXxx の固定値で動作する。
        // 用途を限定したい場合は false にすること。

        // オプションセクション全体を表示するか
        'showOptionSection'    => true,

        // 文字コード選択を表示するか
        'showEncodingSelect'   => true,
        // バリデーションモード選択を表示するか
        'showModeSelect'         => true,
        // インポート方式選択を表示するか（追記 / 全件入れ替え）
        'showImportStrategySelect' => true,
        // 重複データの処理方法選択を表示するか
        'showDuplicateModeSelect' => true,

        // --- 固定値（showXxx が false のときに使用される値） ---
        // showXxx が true の場合でも、GUIの初期選択値として使われる。

        // 文字コード
        //   'auto'      : 自動判別（UTF-8 → Shift-JIS の順で試行）
        //   'UTF-8'     : UTF-8 固定
        //   'Shift-JIS' : Shift-JIS 固定
        'defaultEncoding'      => 'auto',

        // バリデーションモード
        //   'strict'  : 事前確認モード。全件バリデーション後にエラー0件の場合のみ登録する
        //   'lenient' : スキップモード。エラー行を読み飛ばして登録する
        'defaultMode'          => 'strict',

        // インポート方式
        //   'append'  : 既存データを残したまま追記・更新する
        //   'replace' : 登録直前に対象テーブルの既存データを全削除してから取り込む
        //               strict では検証エラー時は削除しない
        //               lenient では最初の登録バッチ直前に削除する
        'defaultImportStrategy' => 'append',

        // 重複データの処理方法（重複キーは各サービスクラスの getDuplicateKey() で定義）
        //   'skip'      : 既存レコードを変更しない
        //   'overwrite' : 既存レコードを上書き更新する
        //   'error'     : 重複をエラーとして報告し、登録しない
        'defaultDuplicateMode' => 'skip',
    ],
];

if (file_exists(__DIR__ . DS . 'setting_customize.php')) {
    include __DIR__ . DS . 'setting_customize.php';
    $config = Hash::merge($config, $customize_config);
}

return $config;
