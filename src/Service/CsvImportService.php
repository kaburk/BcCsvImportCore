<?php

namespace BcCsvImportCore\Service;

use BcCsvImportCore\Model\Entity\BcCsvImportJob;
use BcCsvImportCore\Model\Table\BcCsvImportJobsTable;
use Cake\Http\CallbackStream;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Text;
use Psr\Http\Message\StreamInterface;

/**
 * CsvImportService
 *
 * CSV一括インポートの汎用基底クラス。
 * 専用プラグインを作成する場合はこのクラスを継承し、abstract メソッドを実装する。
 */
abstract class CsvImportService implements CsvImportServiceInterface
{

    /**
     * @var BcCsvImportJobsTable|Table
     */
    protected BcCsvImportJobsTable|Table $Jobs;

    /**
     * 一時ファイル保存ディレクトリ
     * @var string
     */
    protected string $uploadDir;

    /**
     * ジョブ作成時に渡された追加メタデータ
     * サブクラスの buildEntity() 等から参照できる。
     * 例: ['blog_content_id' => 3]
     *
     * @var array
     */
    protected array $jobMeta = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->Jobs = TableRegistry::getTableLocator()->get('BcCsvImportCore.BcCsvImportJobs');
        $this->uploadDir = TMP . 'csv_imports' . DS;
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    /**
     * インポート対象のテーブル名を返す（サブクラスで実装）
     *
     * @return string
     */
    abstract public function getTableName(): string;

    /**
     * CSVカラムマップを返す（サブクラスで実装）
     * [CSVヘッダキー => ['label' => '表示名', 'required' => true/false]]
     *
     * @return array
     */
    abstract public function getColumnMap(): array;

    /**
     * 重複チェックに使うカラム名を返す（サブクラスで実装）
     *
     * @return string
     */
    abstract public function getDuplicateKey(): string;

    /**
     * CSV1行（連想配列）からEntityを生成する（サブクラスで実装）
     *
     * @param array $row
     * @return EntityInterface
     */
    abstract public function buildEntity(array $row): EntityInterface;

    /**
     * ジョブ作成時に渡されたメタデータを取得する
     *
     * サブクラスの buildEntity() 内などで追加パラメータを参照する場合に使用する。
     *
     * @param string $key 取得するキー名
     * @param mixed $default キーが存在しない場合のデフォルト値
     * @return mixed
     */
    public function getJobMeta(string $key, mixed $default = null): mixed
    {
        return $this->jobMeta[$key] ?? $default;
    }

    /**
     * ジョブを作成する
     *
     * @param string $csvPath
     * @param array $options
     * @return BcCsvImportJob
     */
    public function createJob(string $csvPath, array $options = []): BcCsvImportJob
    {
        // 追加メタデータをサブクラスから参照できるよう保持する
        $this->jobMeta = $options['meta'] ?? [];

        $this->validateCsvHeaders($csvPath);

        $expireDays = Configure::read('BcCsvImportCore.csvExpireDays', 3);
        $total = $this->countCsvRows($csvPath);
        $token = Text::uuid();
        $mode = $options['mode'] ?? 'strict';
        $importStrategy = $options['import_strategy'] ?? 'append';
        $errorLogPath = $this->uploadDir . 'errors_' . $token . '.jsonl';
        touch($errorLogPath);

        /** @var BcCsvImportJob $job */
        $job = $this->Jobs->newEntity([
            'job_token' => $token,
            'job_meta'  => empty($this->jobMeta) ? null : json_encode($this->jobMeta, JSON_UNESCAPED_UNICODE),
            'target_table' => $this->getTableName(),
            'phase' => $mode === 'lenient' ? 'import' : 'validate',
            'total' => $total,
            'processed' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'skip_count' => 0,
            'status' => 'pending',
            'mode' => $mode,
            'import_strategy' => $importStrategy,
            'duplicate_mode' => $options['duplicate_mode'] ?? 'skip',
            'csv_path' => $csvPath,
            'validate_position' => 0,
            'import_position' => 0,
            'target_cleared' => false,
            'error_log_path' => $errorLogPath,
            'expires_at' => new DateTime("+{$expireDays} days"),
        ]);

        $job = $this->Jobs->saveOrFail($job);

        Log::info(sprintf(
            '[BcCsvImportCore] job_created token=%s table=%s total=%d mode=%s strategy=%s duplicate=%s',
            $job->job_token,
            $job->target_table,
            $job->total,
            $job->mode,
            $job->import_strategy,
            $job->duplicate_mode
        ), 'csv_import');

        return $job;
    }

    /**
     * バリデーションのバッチ処理（1000件ずつ）
     *
     * @param string $token
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function validateBatch(string $token, int $offset, int $limit): array
    {
        $job = $this->getJobByToken($token);
        $columnMap = $this->getColumnMap();
        $headers = array_keys($columnMap);

        if ($job->status === 'cancelled') {
            return [
                'token' => $token,
                'processed' => (int)$job->processed,
                'total' => (int)$job->total,
                'error_count' => (int)$job->error_count,
                'batch_errors' => [],
                'phase_completed' => true,
            ];
        }

        if ($job->status === 'pending') {
            $job = $this->Jobs->patchEntity($job, ['status' => 'processing', 'started_at' => new DateTime()]);
            $this->Jobs->saveOrFail($job);
        }

        $batch = $this->readCsvBatchByPosition($job->csv_path, (int)($job->validate_position ?? 0), $limit);
        $rows = $batch['rows'];
        $batchErrors = [];
        $baseProcessed = (int)$job->processed;

        foreach ($rows as $i => $row) {
            $rowNumber = $baseProcessed + $i + 2; // ヘッダ行を考慮して+2
            $data = array_combine($headers, array_pad($row, count($headers), null));
            try {
                $entity = $this->buildEntity($data);
            } catch (\Throwable $e) {
                $batchErrors[] = [
                    'row' => $rowNumber,
                    'field' => '_build',
                    'label' => 'データ変換',
                    'message' => $e->getMessage(),
                    'data' => $row,
                ];
                continue;
            }
            if ($entity->hasErrors()) {
                foreach ($entity->getErrors() as $field => $fieldErrors) {
                    foreach ($fieldErrors as $message) {
                        $batchErrors[] = [
                            'row' => $rowNumber,
                            'field' => $field,
                            'label' => $columnMap[$field]['label'] ?? $field,
                            'message' => $message,
                            'data' => $row,
                        ];
                    }
                }
            }
        }

        $this->appendErrorsToLog($job, $batchErrors);

        $processed = $baseProcessed + count($rows);
        $totalErrorCount = (int)$job->error_count + count($batchErrors);
        $phaseCompleted = $processed >= (int)$job->total || count($rows) === 0;
        $updateData = [
            'processed' => $processed,
            'error_count' => $totalErrorCount,
            'validate_position' => $batch['next_position'],
        ];

        if ($phaseCompleted) {
            if ($totalErrorCount > 0) {
                $updateData['status'] = 'failed';
                $updateData['ended_at'] = new DateTime();
                $updateData['phase'] = 'validate';
            } else {
                // 全件バリデーション完了
                $updateData['phase'] = 'import';
                $updateData['processed'] = 0;
                $updateData['import_position'] = 0;
            }
        }

        $job = $this->Jobs->patchEntity($job, $updateData);
        $this->Jobs->saveOrFail($job);

        Log::info(sprintf(
            '[BcCsvImportCore] validate_batch token=%s offset=%d processed=%d errors=%d',
            $token, $offset, $processed, $totalErrorCount
        ), 'csv_import');

        return [
            'token' => $token,
            'processed' => $phaseCompleted ? (int)$job->total : $processed,
            'total' => $job->total,
            'error_count' => $totalErrorCount,
            'batch_errors' => $batchErrors,
            'phase_completed' => $phaseCompleted,
        ];
    }

    /**
     * 登録バッチ処理（1000件ずつ）
     *
     * @param string $token
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function processBatch(string $token, int $offset, int $limit): array
    {
        $job = $this->getJobByToken($token);
        $table = TableRegistry::getTableLocator()->get($this->getTableName());
        $columnMap = $this->getColumnMap();
        $headers = array_keys($columnMap);

        if ($job->status === 'cancelled') {
            return [
                'token' => $token,
                'processed' => (int)$job->processed,
                'total' => (int)$job->total,
                'success_count' => (int)$job->success_count,
                'skip_count' => (int)$job->skip_count,
                'error_count' => (int)$job->error_count,
                'completed' => true,
            ];
        }

        if ($job->status === 'pending') {
            $job = $this->Jobs->patchEntity($job, ['status' => 'processing', 'started_at' => new DateTime(), 'phase' => 'import']);
            $this->Jobs->saveOrFail($job);
        } elseif ($job->phase !== 'import') {
            $job = $this->Jobs->patchEntity($job, ['phase' => 'import']);
            $this->Jobs->saveOrFail($job);
        }

        if ($job->import_strategy === 'replace' && !$job->target_cleared) {
            $this->clearTargetTable($job, $table);
            $job = $this->getJobByToken($token);
        }

        $batch = $this->readCsvBatchByPosition($job->csv_path, (int)($job->import_position ?? 0), $limit);
        $rows = $batch['rows'];
        $successCount = 0;
        $skipCount = 0;
        $batchErrors = [];
        $baseProcessed = (int)$job->processed;
        $duplicateKey = $this->getDuplicateKey();

        $candidateData = [];
        foreach ($rows as $i => $row) {
            $data = array_combine($headers, array_pad($row, count($headers), null));
            $candidateData[$i] = $data;
        }

        $existingEntityMap = $duplicateKey
            ? $this->findExistingEntityMap($table, $duplicateKey, $candidateData)
            : [];

        $connection = $table->getConnection();
        $connection->begin();

        try {
            foreach ($rows as $i => $row) {
                $rowNumber = $baseProcessed + $i + 2;
                $data = $candidateData[$i];
                try {
                    $entity = $this->buildEntity($data);
                } catch (\Throwable $e) {
                    $skipCount++;
                    $batchErrors[] = [
                        'row' => $rowNumber,
                        'field' => '_build',
                        'label' => 'データ変換',
                        'message' => $e->getMessage(),
                        'data' => $row,
                    ];
                    continue;
                }

                // バリデーションエラーチェック
                if ($entity->hasErrors()) {
                    if ($job->mode === 'lenient') {
                        $skipCount++;
                        foreach ($entity->getErrors() as $field => $fieldErrors) {
                            foreach ($fieldErrors as $message) {
                                $batchErrors[] = [
                                    'row' => $rowNumber,
                                    'field' => $field,
                                    'label' => $columnMap[$field]['label'] ?? $field,
                                    'message' => $message,
                                    'data' => $row,
                                ];
                            }
                        }
                        continue;
                    }
                    // strictモードではvalidateBatchを先にまわしてからprocessBatchを呼ぶ想定なので、エラーはスキップ
                    $skipCount++;
                    continue;
                }

                $existingEntity = null;
                $duplicateIdentity = null;
                if ($duplicateKey) {
                    $duplicateIdentity = $this->buildDuplicateIdentity($data, $duplicateKey);
                    if ($duplicateIdentity !== null) {
                        $existingEntity = $existingEntityMap[$duplicateIdentity] ?? null;
                    }
                }

                if ($existingEntity) {
                    switch ($job->duplicate_mode) {
                        case 'skip':
                            $skipCount++;
                            continue 2;
                        case 'overwrite':
                            $entity = $table->patchEntity($existingEntity, $data, ['validate' => false]);
                            break;
                        case 'error':
                            $batchErrors[] = [
                                'row' => $rowNumber,
                                'field' => $duplicateKey,
                                'label' => $columnMap[$duplicateKey]['label'] ?? $duplicateKey,
                                'message' => __d('baser_core', '重複するデータが既に存在します。'),
                                'data' => $row,
                            ];
                            $skipCount++;
                            continue 2;
                    }
                }

                if ($table->save($entity, ['checkRules' => false, 'validate' => false])) {
                    $successCount++;
                    if ($duplicateIdentity !== null) {
                        $existingEntityMap[$duplicateIdentity] = $entity;
                    }
                } else {
                    $skipCount++;
                    foreach ($entity->getErrors() as $field => $fieldErrors) {
                        foreach ($fieldErrors as $message) {
                            $batchErrors[] = [
                                'row' => $rowNumber,
                                'field' => $field,
                                'label' => $columnMap[$field]['label'] ?? $field,
                                'message' => $message,
                                'data' => $row,
                            ];
                        }
                    }
                }
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }

        $this->appendErrorsToLog($job, $batchErrors);

        $processed = $baseProcessed + count($rows);
        $totalSuccess = ($job->success_count ?? 0) + $successCount;
        $totalSkip = ($job->skip_count ?? 0) + $skipCount;
        $totalErrors = ($job->error_count ?? 0) + count($batchErrors);
        $completed = $processed >= (int)$job->total || count($rows) === 0;
        $updateData = [
            'processed' => $processed,
            'success_count' => $totalSuccess,
            'skip_count' => $totalSkip,
            'error_count' => $totalErrors,
            'import_position' => $batch['next_position'],
            'phase' => 'import',
        ];

        if ($completed) {
            $updateData['status'] = 'completed';
            $updateData['ended_at'] = new DateTime();
        }

        $job = $this->Jobs->patchEntity($job, $updateData);
        $this->Jobs->saveOrFail($job);

        Log::info(sprintf(
            '[BcCsvImportCore] process_batch token=%s offset=%d success=%d skip=%d errors=%d',
            $token, $offset, $successCount, $skipCount, $totalErrors
        ), 'csv_import');

        if ($completed) {
            Log::info(sprintf(
                '[BcCsvImportCore] job_completed token=%s total=%d success=%d skip=%d errors=%d',
                $token, $job->total, $totalSuccess, $totalSkip, $totalErrors
            ), 'csv_import');
        }

        return [
            'token' => $token,
            'processed' => $completed ? (int)$job->total : $processed,
            'total' => $job->total,
            'success_count' => $totalSuccess,
            'skip_count' => $totalSkip,
            'error_count' => $totalErrors,
            'completed' => $completed,
            'batch_errors' => array_slice($batchErrors, 0, 200),
        ];
    }

    /**
     * 重複チェック対象の既存エンティティを取得し、識別子ごとのマップを返す
     *
     * @param Table $table
     * @param string $duplicateKey
     * @param array $candidateData
     * @return array
     */
    protected function findExistingEntityMap(Table $table, string $duplicateKey, array $candidateData): array
    {
        $conditions = $this->buildDuplicateSearchConditions($duplicateKey, $candidateData);
        if (!$conditions) {
            return [];
        }

        $existingEntityMap = [];
        $existingEntities = $table->find()->where($conditions)->all();
        foreach ($existingEntities as $existingEntity) {
            $identity = $this->buildDuplicateIdentityFromEntity($existingEntity, $duplicateKey);
            if ($identity !== null) {
                $existingEntityMap[$identity] = $existingEntity;
            }
        }

        return $existingEntityMap;
    }

    /**
     * 重複検索用の条件を構築する
     *
     * @param string $duplicateKey
     * @param array $candidateData
     * @return array
     */
    protected function buildDuplicateSearchConditions(string $duplicateKey, array $candidateData): array
    {
        $candidateKeys = [];
        foreach ($candidateData as $data) {
            if (!empty($data[$duplicateKey])) {
                $candidateKeys[] = $data[$duplicateKey];
            }
        }

        if (!$candidateKeys) {
            return [];
        }

        return [$duplicateKey . ' IN' => array_values(array_unique($candidateKeys))];
    }

    /**
     * 入力データから重複判定用の識別子を構築する
     *
     * @param array $data
     * @param string $duplicateKey
     * @return string|null
     */
    protected function buildDuplicateIdentity(array $data, string $duplicateKey): ?string
    {
        if (empty($data[$duplicateKey])) {
            return null;
        }

        return (string)$data[$duplicateKey];
    }

    /**
     * 既存エンティティから重複判定用の識別子を構築する
     *
     * @param EntityInterface $entity
     * @param string $duplicateKey
     * @return string|null
     */
    protected function buildDuplicateIdentityFromEntity(EntityInterface $entity, string $duplicateKey): ?string
    {
        $value = $entity->get($duplicateKey);
        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    /**
     * ジョブのステータスを取得する
     *
     * @param string $token
     * @return array
     */
    public function getJobStatus(string $token): array
    {
        $job = $this->getJobByToken($token);
        return [
            'token' => $job->job_token,
            'phase' => $job->phase,
            'total' => $job->total,
            'processed' => $job->processed,
            'success_count' => $job->success_count,
            'error_count' => $job->error_count,
            'skip_count' => $job->skip_count,
            'status' => $job->status,
            'mode' => $job->mode,
            'import_strategy' => $job->import_strategy,
            'duplicate_mode' => $job->duplicate_mode,
            'target_cleared' => (bool)$job->target_cleared,
            'started_at' => $job->started_at?->toIso8601String(),
            'ended_at' => $job->ended_at?->toIso8601String(),
        ];
    }

    /**
     * ジョブをキャンセルする
     *
     * @param string $token
     * @return bool
     */
    public function cancelJob(string $token): bool
    {
        $job = $this->getJobByToken($token);
        $job = $this->Jobs->patchEntity($job, ['status' => 'cancelled', 'ended_at' => new DateTime()]);
        $result = (bool)$this->Jobs->save($job);

        Log::info(sprintf(
            '[BcCsvImportCore] job_cancelled token=%s processed=%d total=%d',
            $token, (int)$job->processed, (int)$job->total
        ), 'csv_import');

        return $result;
    }

    /**
     * ジョブを削除する
     *
     * @param string $token
     * @return bool
     */
    public function deleteJob(string $token): bool
    {
        $job = $this->getJobByToken($token);

        if ($job->csv_path && file_exists($job->csv_path)) {
            unlink($job->csv_path);
        }
        if ($job->error_log_path && file_exists($job->error_log_path)) {
            unlink($job->error_log_path);
        }

        $result = (bool)$this->Jobs->delete($job);

        Log::info(sprintf(
            '[BcCsvImportCore] job_deleted token=%s status=%s',
            $token, $job->status
        ), 'csv_import');

        return $result;
    }

    /**
     * テンプレートCSVの内容を生成する
     *
     * @return string
     */
    public function buildTemplateCsv(): string
    {
        $columnMap = $this->getColumnMap();
        $headers = array_map(fn($v) => $v['label'], $columnMap);
        $sampleRow = array_map(fn($v) => $v['sample'] ?? '', $columnMap);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        fputcsv($output, $sampleRow);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * エラーCSVの内容を生成する
     *
     * @param string $token
     * @return string
     */
    public function buildErrorCsv(string $token): string
    {
        $job = $this->getJobByToken($token);
        $errors = $this->readErrorsFromLog($job);
        $columnMap = $this->getColumnMap();
        $headers = array_map(fn($v) => $v['label'], $columnMap);

        $output = fopen('php://temp', 'r+');
        // ヘッダ: 元のカラム + エラー行番号 + エラー内容
        fputcsv($output, array_merge($headers, [__d('baser_core', '行番号'), __d('baser_core', 'エラー内容')]));

        foreach ($errors as $error) {
            $row = array_pad($error['data'], count($headers), '');
            $row[] = $error['row'];
            $row[] = '[' . ($error['label'] ?? $error['field']) . '] ' . $error['message'];
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * エラーCSVの内容をストリームとして生成する
     *
     * @param string $token
     * @return StreamInterface
     */
    public function buildErrorCsvStream(string $token): StreamInterface
    {
        $job = $this->getJobByToken($token);
        $columnMap = $this->getColumnMap();
        $headers = array_map(fn($v) => $v['label'], $columnMap);
        $headerRow = array_merge($headers, [__d('baser_core', '行番号'), __d('baser_core', 'エラー内容')]);
        $path = $job->error_log_path ?: $this->uploadDir . 'errors_' . $job->job_token . '.jsonl';

        return new CallbackStream(function () use ($path, $headerRow, $headers): void {
            echo $this->buildCsvLine($headerRow);

            if (!$path || !file_exists($path)) {
                return;
            }

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    $error = json_decode(trim($line), true);
                    if (!is_array($error)) {
                        continue;
                    }

                    $row = array_pad($error['data'] ?? [], count($headers), '');
                    $row[] = $error['row'] ?? '';
                    $row[] = '[' . ($error['label'] ?? $error['field'] ?? '') . '] ' . ($error['message'] ?? '');
                    echo $this->buildCsvLine($row);
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * 期限切れの一時ファイルを削除する
     *
     * @return int 削除件数
     */
    public function cleanupExpiredFiles(): int
    {
        $expiredJobs = $this->Jobs->find()
            ->where(['expires_at <' => new DateTime()])
            ->all();

        $count = 0;
        foreach ($expiredJobs as $job) {
            if ($job->csv_path && file_exists($job->csv_path)) {
                unlink($job->csv_path);
                $count++;
            }
            if ($job->error_log_path && file_exists($job->error_log_path)) {
                unlink($job->error_log_path);
            }
            $this->Jobs->delete($job);
        }

        return $count;
    }

    /**
     * CSVのヘッダ行を期待するカラムマップと照合し、不一致があれば例外を投げる
     *
     * 不足列・余分な列・順序違いを個別に報告する。
     *
     * @param string $csvPath
     * @return void
     * @throws \RuntimeException ヘッダが一致しない場合
     */
    protected function validateCsvHeaders(string $csvPath): void
    {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException(__d('baser_core', 'CSVファイルを開けませんでした。'));
        }
        $actualHeaders = fgetcsv($handle);
        fclose($handle);

        if ($actualHeaders === false || $actualHeaders === [null]) {
            throw new \RuntimeException(__d('baser_core', 'CSVファイルにヘッダ行がありません。'));
        }

        $columnMap = $this->getColumnMap();
        $expectedLabels = array_values(array_map(fn($v) => $v['label'], $columnMap));
        $actualHeaders  = array_values($actualHeaders);

        if ($actualHeaders === $expectedLabels) {
            return; // 完全一致
        }

        $missing = array_diff($expectedLabels, $actualHeaders);
        $extra   = array_diff($actualHeaders, $expectedLabels);

        Log::warning(sprintf(
            '[BcCsvImportCore] header_mismatch file=%s expected=[%s] actual=[%s]',
            basename($csvPath),
            implode(',', $expectedLabels),
            implode(',', $actualHeaders)
        ), 'csv_import');

        throw new \RuntimeException(
            __d('baser_core', 'CSVのヘッダが一致しません。') . "\n\n" .
            __d('baser_core', '■ 正しいヘッダ行') . "\n" .
            implode(', ', $expectedLabels) . "\n\n" .
            __d('baser_core', '■ アップロードされたヘッダ行') . "\n" .
            implode(', ', $actualHeaders)
        );
    }

    /**
     * CSVの総行数（ヘッダ除く）を返す
     *
     * @param string $csvPath
     * @return int
     */
    protected function countCsvRows(string $csvPath): int
    {
        $count = 0;
        if (($handle = fopen($csvPath, 'r')) === false) {
            return 0;
        }
        fgetcsv($handle); // ヘッダスキップ
        while (($row = fgetcsv($handle)) !== false) {
            if ($row !== [null]) {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    /**
     * CSVをオフセット指定で読み込む
     *
     * @param string $csvPath
     * @param int $offset
     * @param int $limit
     * @return array
     */
    protected function readCsvBatchByPosition(string $csvPath, int $position, int $limit): array
    {
        $rows = [];
        if (($handle = fopen($csvPath, 'r')) === false) {
            return ['rows' => [], 'next_position' => $position];
        }

        if ($position > 0) {
            fseek($handle, $position);
        } else {
            fgetcsv($handle); // ヘッダスキップ
        }

        while (count($rows) < $limit && ($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue; // 空行スキップ
            }
            $rows[] = $row;
        }

        $nextPosition = ftell($handle);
        fclose($handle);
        return ['rows' => $rows, 'next_position' => $nextPosition === false ? $position : $nextPosition];
    }

    /**
     * エラーをJSON Lines形式で追記する
     *
     * @param BcCsvImportJob $job
     * @param array $errors
     * @return void
     */
    protected function appendErrorsToLog(BcCsvImportJob $job, array $errors): void
    {
        if (!$errors) {
            return;
        }

        $path = $job->error_log_path ?: $this->uploadDir . 'errors_' . $job->job_token . '.jsonl';
        if (empty($job->error_log_path)) {
            $job = $this->Jobs->patchEntity($job, ['error_log_path' => $path]);
            $this->Jobs->saveOrFail($job);
        }
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            return;
        }

        foreach ($errors as $error) {
            fwrite($handle, json_encode($error, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        }
        fclose($handle);
    }

    /**
     * 1行分のCSV文字列を生成する
     *
     * @param array $row
     * @return string
     */
    protected function buildCsvLine(array $row): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return '';
        }

        fputcsv($output, $row);
        rewind($output);
        $csvLine = stream_get_contents($output) ?: '';
        fclose($output);

        return $csvLine;
    }

    /**
     * エラーログファイルからエントリを読み込む
     *
     * @param BcCsvImportJob $job
     * @return array
     */
    protected function readErrorsFromLog(BcCsvImportJob $job): array
    {
        $path = $job->error_log_path ?: $this->uploadDir . 'errors_' . $job->job_token . '.jsonl';

        if ($path && file_exists($path)) {
            $errors = [];
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return [];
            }
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $errors[] = $decoded;
                }
            }
            fclose($handle);
            return $errors;
        }

        return [];
    }

    /**
     * 文字コードを自動判別する
     *
     * @param string $csvPath
     * @return string
     */
    public function detectEncoding(string $csvPath): string
    {
        $sample = file_get_contents($csvPath, false, null, 0, 8192);
        if ($sample === false) {
            return 'UTF-8';
        }
        $detected = mb_detect_encoding($sample, ['UTF-8', 'SJIS-win', 'EUC-JP', 'JIS'], true);
        return $detected ?: 'UTF-8';
    }

    /**
     * CSVをUTF-8に変換して保存する
     *
     * @param string $csvPath
     * @param string $fromEncoding
     * @return void
     */
    public function convertCsvEncoding(string $csvPath, string $fromEncoding): void
    {
        $content = file_get_contents($csvPath);
        if ($content === false) {
            return;
        }

        // UTF-8 BOM（EF BB BF）を除去
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if ($fromEncoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $fromEncoding);
        }

        file_put_contents($csvPath, $content);
    }

    /**
     * トークンからジョブを取得する
     *
     * @param string $token
     * @return BcCsvImportJob
     */
    protected function getJobByToken(string $token): BcCsvImportJob
    {
        /** @var BcCsvImportJob $job */
        $job = $this->Jobs->find()
            ->where(['job_token' => $token])
            ->firstOrFail();

        // job_meta を復元して、バッチ処理リクエスト間でも getJobMeta() が機能するようにする
        if (!empty($job->job_meta)) {
            $decoded = json_decode((string)$job->job_meta, true);
            $this->jobMeta = is_array($decoded) ? $decoded : [];
        }

        return $job;
    }

    /**
     * 対象テーブルを全削除する
     *
     * @param BcCsvImportJob $job
     * @param Table $table
     * @return void
     */
    protected function clearTargetTable(BcCsvImportJob $job, Table $table): void
    {
        $connection = $table->getConnection();
        $tableName = $table->getTable();
        $quotedTable = $connection->getDriver()->quoteIdentifier($tableName);
        $driverClass = get_class($connection->getDriver());
        $clearMethod = 'delete';

        try {
            if (stripos($driverClass, 'Postgres') !== false) {
                $connection->execute('TRUNCATE TABLE ' . $quotedTable . ' RESTART IDENTITY');
            } else {
                $connection->execute('TRUNCATE TABLE ' . $quotedTable);
            }
            $clearMethod = 'truncate';
        } catch (\Throwable $e) {
            $table->deleteAll([]);

            try {
                if (stripos($driverClass, 'Sqlite') !== false) {
                    $connection->execute("DELETE FROM sqlite_sequence WHERE name = ?", [$tableName]);
                }
            } catch (\Throwable) {
            }

            Log::warning(sprintf(
                '[BcCsvImportCore] replace mode truncate failed. fallback to deleteAll. token=%s table=%s message=%s',
                $job->job_token,
                $tableName,
                $e->getMessage()
            ), 'csv_import');
        }

        $job = $this->Jobs->patchEntity($job, [
            'target_cleared' => true,
        ]);
        $this->Jobs->saveOrFail($job);

        Log::info(sprintf(
            '[BcCsvImportCore] target table cleared. token=%s table=%s method=%s',
            $job->job_token,
            $tableName,
            $clearMethod
        ), 'csv_import');
    }

}
