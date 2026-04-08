<?php

namespace BcCsvImportCore\Controller\Admin;

use BaserCore\Controller\Admin\BcAdminAppController;
use BcCsvImportCore\Service\Admin\CsvImportAdminService;
use BcCsvImportCore\Service\Admin\CsvImportAdminServiceInterface;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Log\Log;
use Throwable;

/**
 * CsvImportsController
 *
 * 管理画面用CSVインポートコントローラー（AJAX JSONレスポンスを含む）
 */
class CsvImportsController extends BcAdminAppController
{

    /**
     * インポートサービス（サブクラスで createImportService() をオーバーライドして差し替える）
     *
     * @var CsvImportServiceInterface
     */
    protected CsvImportServiceInterface $importService;

    /**
     * 管理サービス（サブクラスで createAdminService() をオーバーライドして差し替える）
     *
     * @var CsvImportAdminServiceInterface
     */
    protected CsvImportAdminServiceInterface $adminService;

    /**
     * beforeFilter
     *
     * AJAX処理アクションは FormProtection の _Token チェックを除外する
     *
     * @param EventInterface $event
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->FormProtection->setConfig('unlockedActions', [
            'upload',
            'validate_batch',
            'process_batch',
            'status',
            'cancel',
            'delete',
        ]);

        $this->importService = $this->createImportService();
        $this->adminService = $this->createAdminService();
        $this->adminService->setTargetTable($this->importService->getTableName());
    }

    /**
     * インポートサービスを生成する
     *
     * サブクラスでオーバーライドして独自サービスを返す。
     * コアコントローラー単体では未設定のため RuntimeException を投げる。
     *
     * @return CsvImportServiceInterface
     */
    protected function createImportService(): CsvImportServiceInterface
    {
        throw new \RuntimeException(
            'createImportService() is not implemented. ' .
            'Please enable a sub-plugin such as BcCsvImportSampleProducts.'
        );
    }

    /**
     * 管理サービスを生成する
     *
     * 追加の View 変数が必要なサブクラスでオーバーライドする。
     *
     * @return CsvImportAdminServiceInterface
     */
    protected function createAdminService(): CsvImportAdminServiceInterface
    {
        return new CsvImportAdminService();
    }

    /**
     * CSVアップロード画面
     *
     * @return void
     */
    public function index(): void
    {
        $this->set(array_merge(
            $this->adminService->getViewVarsForIndex(),
            $this->resolveUiSettings()
        ));
    }

    /**
     * プラグイン固有の UI 設定を解決して view 変数として返す
     *
     * 自プラグイン名のキーを優先し、未設定 (null) の場合は BcCsvImportCore にフォールバックする。
     * これにより複数の派生プラグインを同時有効化しても Configure が衝突しない。
     *
     * @return array
     */
    private function resolveUiSettings(): array
    {
        $pluginName = $this->plugin ?? 'BcCsvImportCore';
        $cfg = function (string $key, mixed $default) use ($pluginName): mixed {
            $val = Configure::read($pluginName . '.' . $key);
            if ($val !== null) {
                return $val;
            }
            return Configure::read('BcCsvImportCore.' . $key, $default);
        };

        return [
            'showOptionSection'     => $cfg('showOptionSection', true),
            'showEncoding'          => $cfg('showEncodingSelect', true),
            'showMode'              => $cfg('showModeSelect', true),
            'showImportStrategy'    => $cfg('showImportStrategySelect', true),
            'showDuplicate'         => $cfg('showDuplicateModeSelect', true),
            'defaultEncoding'       => $cfg('defaultEncoding', 'auto'),
            'defaultMode'           => $cfg('defaultMode', 'strict'),
            'defaultImportStrategy' => $cfg('defaultImportStrategy', 'append'),
            'defaultDuplicate'      => $cfg('defaultDuplicateMode', 'skip'),
        ];
    }

    /**
     * [AJAX] CSVアップロード＋ジョブ作成
     *
     * @return Response
     */
    public function upload(): Response
    {
        $this->request->allowMethod('post');

        $uploadedFile = $this->request->getUploadedFile('csv_file');
        $encoding = $this->request->getData('encoding', 'auto');
        $mode = $this->request->getData('mode', 'strict');
        $importStrategy = $this->request->getData('import_strategy', 'append');
        $duplicateMode = $this->request->getData('duplicate_mode', 'skip');

        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->_json(['message' => __d('baser_core', 'CSVファイルをアップロードしてください。')], 400);
        }

        try {
            $tmpDir = TMP . 'csv_imports' . DS;
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0777, true);
            }
            $tmpPath = $tmpDir . uniqid('csv_', true) . '.csv';
            $uploadedFile->moveTo($tmpPath);

            $resolvedEncoding = $encoding === 'auto'
                ? $this->importService->detectEncoding($tmpPath)
                : $encoding;
            $this->importService->convertCsvEncoding($tmpPath, $resolvedEncoding);

            $job = $this->importService->createJob($tmpPath, [
                'mode' => $mode,
                'import_strategy' => $importStrategy,
                'duplicate_mode' => $duplicateMode,
            ]);

            return $this->_json([
                'job' => [
                    'token' => $job->job_token,
                    'total' => $job->total,
                    'mode' => $job->mode,
                    'import_strategy' => $job->import_strategy,
                    'target_cleared' => (bool)$job->target_cleared,
                    'duplicate_mode' => $job->duplicate_mode,
                    'encoding' => $resolvedEncoding,
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($tmpPath) && file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            $isValidationError = $e instanceof \RuntimeException
                && str_contains($e->getMessage(), 'CSVのヘッダ');
            if ($isValidationError) {
                Log::warning('[BcCsvImportCore] header_validation_error ' . $e->getMessage(), 'csv_import');
                return $this->_json(['message' => $e->getMessage()], 400);
            }
            Log::error('[BcCsvImportCore] upload_error ' . $e->getMessage(), 'csv_import');
            return $this->_json(['message' => __d('baser_core', 'アップロードに失敗しました。') . $e->getMessage()], 500);
        }
    }

    /**
     * [AJAX] バリデーションバッチ処理（モードA 1パス目）
     *
     * @return Response
     */
    public function validate_batch(): Response
    {
        $this->request->allowMethod('post');

        $token = $this->request->getData('token', '');
        $offset = (int)$this->request->getData('offset', 0);
        $limit = (int)$this->request->getData('limit', 1000);

        if (!$token) {
            return $this->_json(['message' => __d('baser_core', 'トークンが指定されていません。')], 400);
        }

        try {
            return $this->_json(['result' => $this->importService->validateBatch($token, $offset, $limit)]);
        } catch (Throwable $e) {
            Log::error(sprintf('[BcCsvImportCore] validate_batch_error token=%s %s', $token, $e->getMessage()), 'csv_import');
            return $this->_json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * [AJAX] 登録バッチ処理（モードA 2パス目 / モードB）
     *
     * @return Response
     */
    public function process_batch(): Response
    {
        $this->request->allowMethod('post');

        $token = $this->request->getData('token', '');
        $offset = (int)$this->request->getData('offset', 0);
        $limit = (int)$this->request->getData('limit', 1000);

        if (!$token) {
            return $this->_json(['message' => __d('baser_core', 'トークンが指定されていません。')], 400);
        }

        try {
            return $this->_json(['result' => $this->importService->processBatch($token, $offset, $limit)]);
        } catch (Throwable $e) {
            Log::error(sprintf('[BcCsvImportCore] process_batch_error token=%s %s', $token, $e->getMessage()), 'csv_import');
            return $this->_json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * [AJAX] ジョブの進捗確認
     *
     * @param string $token
     * @return Response
     */
    public function status(string $token): Response
    {
        $this->request->allowMethod(['get', 'post']);

        try {
            return $this->_json(['status' => $this->importService->getJobStatus($token)]);
        } catch (Throwable $e) {
            return $this->_json(['message' => __d('baser_core', 'ジョブが見つかりません。')], 404);
        }
    }

    /**
     * [AJAX] ジョブのキャンセル
     *
     * @param string $token
     * @return Response
     */
    public function cancel(string $token): Response
    {
        $this->request->allowMethod('post');

        try {
            $this->importService->cancelJob($token);
            return $this->_json(['message' => __d('baser_core', 'キャンセルしました。')]);
        } catch (Throwable $e) {
            Log::error(sprintf('[BcCsvImportCore] cancel_error token=%s %s', $token, $e->getMessage()), 'csv_import');
            return $this->_json(['message' => __d('baser_core', 'ジョブが見つかりません。')], 404);
        }
    }

    /**
     * [AJAX] ジョブの削除
     *
     * @param string $token
     * @return Response
     */
    public function delete(string $token): Response
    {
        $this->request->allowMethod('post');

        try {
            $this->importService->deleteJob($token);
            return $this->_json(['message' => __d('baser_core', 'ジョブを削除しました。')]);
        } catch (Throwable $e) {
            return $this->_json(['message' => __d('baser_core', 'ジョブが見つかりません。')], 404);
        }
    }

    /**
     * テンプレートCSVダウンロード
     *
     * @return Response
     */
    public function download_template(): Response
    {
        $tableName = $this->importService->getTableName();
        $modelName = strtolower(preg_replace(
            '/([a-z])([A-Z])/', '$1_$2',
            str_contains($tableName, '.') ? explode('.', $tableName)[1] : $tableName
        ));
        $filename = $modelName . '-import_template.csv';

        return $this->response
            ->withType('text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStringBody($this->importService->buildTemplateCsv());
    }

    /**
     * エラーCSVダウンロード
     *
     * @param string $token
     * @return Response
     */
    public function download_errors(string $token): Response
    {
        return $this->response
            ->withType('text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="import_errors_' . $token . '.csv"')
            ->withBody($this->importService->buildErrorCsvStream($token));
    }

    /**
     * JSONレスポンスを返す
     *
     * @param array $data
     * @param int $status
     * @return Response
     */
    private function _json(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

}
