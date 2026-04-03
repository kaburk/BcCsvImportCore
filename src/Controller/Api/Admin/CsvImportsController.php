<?php

namespace BcCsvImportCore\Controller\Api\Admin;

use BaserCore\Controller\Api\Admin\BcAdminApiController;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Http\Exception\BadRequestException;
use Throwable;

/**
 * Api/Admin CsvImportsController
 *
 * CSVインポートAPI（管理画面用）
 */
class CsvImportsController extends BcAdminApiController
{

    /**
     * CSVアップロード＋ジョブ作成
     *
     * POST /baser/api/admin/bc-csv-import-core/csv_imports/upload.json
     *
     * リクエストパラメーター:
     * - csv_file: アップロードCSVファイル
     * - encoding: 文字コード（auto / UTF-8 / Shift-JIS）　デフォルト: auto
     * - mode: strict / lenient。デフォルト: strict
    * - import_strategy: append / replace。デフォルト: append
     * - duplicate_mode: skip / overwrite / error。デフォルト: skip
     *
     * @param CsvImportServiceInterface $service
     * @return void
     */
    public function upload(CsvImportServiceInterface $service): void
    {
        $this->request->allowMethod('post');

        $uploadedFile = $this->request->getUploadedFile('csv_file');
        $encoding = $this->request->getData('encoding', 'auto');
        $mode = $this->request->getData('mode', 'strict');
        $importStrategy = $this->request->getData('import_strategy', 'append');
        $duplicateMode = $this->request->getData('duplicate_mode', 'skip');

        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $this->setResponse($this->response->withStatus(400));
            $this->set(['message' => __d('baser_core', 'CSVファイルをアップロードしてください。')]);
            $this->viewBuilder()->setOption('serialize', ['message']);
            return;
        }

        $token = null;
        $job = null;
        try {
            // 一時ファイルに保存
            $tmpDir = TMP . 'csv_imports' . DS;
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0777, true);
            }
            $tmpPath = $tmpDir . uniqid('csv_', true) . '.csv';
            $uploadedFile->moveTo($tmpPath);

            // 文字コード判別・変換
            $resolvedEncoding = $encoding === 'auto'
                ? $service->detectEncoding($tmpPath)
                : $encoding;
            $service->convertCsvEncoding($tmpPath, $resolvedEncoding);

            // ジョブ作成
            $job = $service->createJob($tmpPath, [
                'mode' => $mode,
                'import_strategy' => $importStrategy,
                'duplicate_mode' => $duplicateMode,
            ]);

            $this->set([
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
            $this->viewBuilder()->setOption('serialize', ['job']);
        } catch (Throwable $e) {
            if (isset($tmpPath) && file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            $this->setResponse($this->response->withStatus(500));
            $this->set(['message' => __d('baser_core', 'アップロードに失敗しました。') . $e->getMessage()]);
            $this->viewBuilder()->setOption('serialize', ['message']);
        }
    }

    /**
     * バリデーションバッチ処理（モードA 1パス目）
     *
     * POST /baser/api/admin/bc-csv-import-core/csv_imports/validate_batch.json
     *
     * リクエストパラメーター:
     * - token: ジョブトークン
     * - offset: 読み込み開始行（0始まり）
     * - limit: 処理件数（デフォルト1000）
     *
     * @param CsvImportServiceInterface $service
     * @return void
     */
    public function validateBatch(CsvImportServiceInterface $service): void
    {
        $this->request->allowMethod('post');

        $token = $this->request->getData('token', '');
        $offset = (int)$this->request->getData('offset', 0);
        $limit = (int)$this->request->getData('limit', 1000);

        if (!$token) {
            $this->setResponse($this->response->withStatus(400));
            $this->set(['message' => __d('baser_core', 'トークンが指定されていません。')]);
            $this->viewBuilder()->setOption('serialize', ['message']);
            return;
        }

        try {
            $result = $service->validateBatch($token, $offset, $limit);
            $this->set(['result' => $result]);
            $this->viewBuilder()->setOption('serialize', ['result']);
        } catch (Throwable $e) {
            $this->setResponse($this->response->withStatus(500));
            $this->set(['message' => $e->getMessage()]);
            $this->viewBuilder()->setOption('serialize', ['message']);
        }
    }

    /**
     * 登録バッチ処理（モードA 2パス目 / モードB）
     *
     * POST /baser/api/admin/bc-csv-import-core/csv_imports/process_batch.json
     *
     * リクエストパラメーター:
     * - token: ジョブトークン
     * - offset: 読み込み開始行（0始まり）
     * - limit: 処理件数（デフォルト1000）
     *
     * @param CsvImportServiceInterface $service
     * @return void
     */
    public function processBatch(CsvImportServiceInterface $service): void
    {
        $this->request->allowMethod('post');

        $token = $this->request->getData('token', '');
        $offset = (int)$this->request->getData('offset', 0);
        $limit = (int)$this->request->getData('limit', 1000);

        if (!$token) {
            $this->setResponse($this->response->withStatus(400));
            $this->set(['message' => __d('baser_core', 'トークンが指定されていません。')]);
            $this->viewBuilder()->setOption('serialize', ['message']);
            return;
        }

        try {
            $result = $service->processBatch($token, $offset, $limit);
            $this->set(['result' => $result]);
            $this->viewBuilder()->setOption('serialize', ['result']);
        } catch (Throwable $e) {
            $this->setResponse($this->response->withStatus(500));
            $this->set(['message' => $e->getMessage()]);
            $this->viewBuilder()->setOption('serialize', ['message']);
        }
    }

    /**
     * ジョブの進捗確認
     *
     * GET /baser/api/admin/bc-csv-import-core/csv_imports/status/{token}.json
     *
     * @param CsvImportServiceInterface $service
     * @param string $token
     * @return void
     */
    public function status(CsvImportServiceInterface $service, string $token): void
    {
        $this->request->allowMethod('get');

        try {
            $status = $service->getJobStatus($token);
            $this->set(['status' => $status]);
            $this->viewBuilder()->setOption('serialize', ['status']);
        } catch (Throwable $e) {
            $this->setResponse($this->response->withStatus(404));
            $this->set(['message' => __d('baser_core', 'ジョブが見つかりません。')]);
            $this->viewBuilder()->setOption('serialize', ['message']);
        }
    }

    /**
     * ジョブのキャンセル
     *
     * POST /baser/api/admin/bc-csv-import-core/csv_imports/cancel/{token}.json
     *
     * @param CsvImportServiceInterface $service
     * @param string $token
     * @return void
     */
    public function cancel(CsvImportServiceInterface $service, string $token): void
    {
        $this->request->allowMethod('post');

        try {
            $service->cancelJob($token);
            $this->set(['message' => __d('baser_core', 'キャンセルしました。')]);
            $this->viewBuilder()->setOption('serialize', ['message']);
        } catch (Throwable $e) {
            $this->setResponse($this->response->withStatus(404));
            $this->set(['message' => __d('baser_core', 'ジョブが見つかりません。')]);
            $this->viewBuilder()->setOption('serialize', ['message']);
        }
    }

    /**
     * テンプレートCSVダウンロード
     *
     * GET /baser/api/admin/bc-csv-import-core/csv_imports/template.json
     *
     * @param CsvImportServiceInterface $service
     * @return \Cake\Http\Response
     */
    public function template(CsvImportServiceInterface $service): \Cake\Http\Response
    {
        $this->request->allowMethod('get');

        $tableName = $service->getTableName();
        $modelName = strtolower(preg_replace(
            '/([a-z])([A-Z])/', '$1_$2',
            str_contains($tableName, '.') ? explode('.', $tableName)[1] : $tableName
        ));
        $filename = $modelName . '-import_template.csv';

        $csvContent = $service->buildTemplateCsv();
        return $this->response
            ->withType('text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStringBody($csvContent);
    }

}
