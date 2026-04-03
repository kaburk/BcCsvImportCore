<?php

namespace BcCsvImportCore\Service\Admin;

use BcCsvImportCore\Model\Entity\BcCsvImportJob;
use BcCsvImportCore\Model\Table\BcCsvImportJobsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * CsvImportAdminService
 */
class CsvImportAdminService implements CsvImportAdminServiceInterface
{

    /**
     * @var BcCsvImportJobsTable|Table
     */
    protected BcCsvImportJobsTable|Table $Jobs;

    /**
     * 一覧対象の target_table
     *
     * @var string|null
     */
    protected ?string $targetTable = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->Jobs = TableRegistry::getTableLocator()->get('BcCsvImportCore.BcCsvImportJobs');
    }

    /**
     * @inheritDoc
     */
    public function setTargetTable(string $targetTable): void
    {
        $this->targetTable = $targetTable;
    }

    /**
     * アップロード画面用の View 変数を取得する
     *
     * @return array
     */
    public function getViewVarsForIndex(): array
    {
        $pendingJobs = $this->decorateJobs($this->getPendingJobs());
        $historyJobs = $this->decorateJobs($this->getHistoryJobs());

        return array_merge(
            [
                'pendingJobs' => $pendingJobs,
                'historyJobs' => $historyJobs,
            ],
            $this->getExtraViewVars()
        );
    }

    /**
     * 追加の View 変数を返す（サブクラスでオーバーライドして拡張する）
     *
     * BcCsvImportBlogPosts など独自のアップロード画面に追加 UI が必要な場合は
     * このメソッドをオーバーライドして変数を追加する。
     *
     * @return array
     */
    protected function getExtraViewVars(): array
    {
        return [];
    }

    /**
     * ジョブの概要を取得する
     *
     * @param string $token
     * @return array
     */
    public function getJobSummary(string $token): array
    {
        /** @var BcCsvImportJob $job */
        $job = $this->Jobs->find()
            ->where(array_merge($this->buildTargetTableConditions(), ['job_token' => $token]))
            ->firstOrFail();

        return $this->buildJobSummary($job);
    }

    /**
     * 一覧表示用のジョブ概要を構築する
     *
     * @param BcCsvImportJob $job
     * @return array
     */
    protected function buildJobSummary(BcCsvImportJob $job): array
    {
        $preview = [];
        foreach ($this->readErrorPreview($job, 3) as $error) {
            $row = $error['row'] ?? null;
            $label = $error['label'] ?? ($error['field'] ?? '');
            $message = $error['message'] ?? '';
            $prefix = $row ? '行' . $row . ': ' : '';
            $preview[] = trim($prefix . $label . ' ' . $message);
        }

        $statusLabels = [
            'pending' => '待機中',
            'processing' => '中断済み',
            'failed' => '検証エラー',
            'completed' => '完了',
            'cancelled' => 'キャンセル済み',
        ];

        $summaryLabel = $statusLabels[$job->status] ?? $job->status;
        if ((int)$job->error_count > 0) {
            $summaryLabel .= ' / エラー ' . number_format((int)$job->error_count) . ' 件';
        }

        return [
            'summary_label' => $summaryLabel,
            'error_preview' => $preview,
            'can_resume' => in_array($job->status, ['pending', 'processing'], true),
            'can_download_errors' => (int)$job->error_count > 0,
            'detail_label' => $this->buildDetailLabel($job),
        ];
    }

    /**
     * エラープレビューを読み込む
     *
     * @param BcCsvImportJob $job
     * @param int $limit
     * @return array
     */
    protected function readErrorPreview(BcCsvImportJob $job, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $errors = [];
        $path = $job->error_log_path;
        if ($path && file_exists($path)) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return [];
            }
            while (count($errors) < $limit && ($line = fgets($handle)) !== false) {
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
     * ジョブ配列へ一覧表示用の属性を付与する
     *
     * @param array $jobs
     * @return array
     */
    protected function decorateJobs(array $jobs): array
    {
        foreach ($jobs as $job) {
            $summary = $this->buildJobSummary($job);
            $job->set('summary_label', $summary['summary_label']);
            $job->set('error_preview', $summary['error_preview']);
            $job->set('can_resume', $summary['can_resume']);
            $job->set('can_download_errors', $summary['can_download_errors']);
            $job->set('detail_label', $summary['detail_label']);
        }

        return $jobs;
    }

    /**
     * 未完了ジョブ一覧を取得する
     *
     * @return array
     */
    protected function getPendingJobs(): array
    {
        return $this->Jobs->find()
            ->where(array_merge($this->buildTargetTableConditions(), [
                'status IN' => ['pending', 'processing', 'failed'],
                'expires_at >' => new DateTime(),
            ]))
            ->orderBy(['created' => 'DESC'])
            ->all()
            ->toList();
    }

    /**
     * 履歴一覧に表示する最近のジョブを取得する
     *
     * @return array
     */
    protected function getHistoryJobs(): array
    {
        return $this->Jobs->find()
            ->where(array_merge($this->buildTargetTableConditions(), [
                'status IN' => ['completed', 'cancelled'],
            ]))
            ->orderBy(['created' => 'DESC'])
            ->limit(20)
            ->all()
            ->toList();
    }

    /**
     * target_table の絞り込み条件を返す
     *
     * @return array
     */
    protected function buildTargetTableConditions(): array
    {
        if (!$this->targetTable) {
            return [];
        }

        return ['target_table' => $this->targetTable];
    }

    /**
     * ジョブの結果要約を構築する
     *
     * @param BcCsvImportJob $job
     * @return string
     */
    protected function buildDetailLabel(BcCsvImportJob $job): string
    {
        if ($job->status === 'completed') {
            return sprintf(
                '成功 %s 件 / スキップ %s 件 / エラー %s 件',
                number_format((int)$job->success_count),
                number_format((int)$job->skip_count),
                number_format((int)$job->error_count)
            );
        }

        if ($job->status === 'cancelled') {
            return sprintf(
                '%s / %s 件でキャンセル',
                number_format((int)$job->processed),
                number_format((int)$job->total)
            );
        }

        if ((int)$job->error_count > 0) {
            return __d('baser_core', 'エラー詳細はCSVをダウンロードして確認してください。');
        }

        return __d('baser_core', '再開可能なジョブです。');
    }

}
