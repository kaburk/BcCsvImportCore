<?php
declare(strict_types=1);

namespace BcCsvImportCore\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * CleanupCommand
 *
 * 期限切れの CSV インポートジョブレコードと付随する一時ファイル（CSV・エラーログ）を削除する。
 * `config/BcCsvImportCore.csvExpireDays` で設定された日数を超えたジョブが対象。
 *
 * 実行例:
 *   bin/cake BcCsvImportCore.cleanup
 *   bin/cake BcCsvImportCore.cleanup --dry-run
 */
class CleanupCommand extends Command
{
    /**
     * コマンド名
     */
    public static function defaultName(): string
    {
        return 'BcCsvImportCore.cleanup';
    }

    /**
     * オプション定義
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('期限切れのCSVインポートジョブと一時ファイルを削除する。');
        $parser->addOption('dry-run', [
            'help' => '実際の削除を行わず、対象件数のみ表示する。',
            'boolean' => true,
            'default' => false,
        ]);

        return $parser;
    }

    /**
     * コマンド実行
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $dryRun = (bool)$args->getOption('dry-run');

        /** @var \BcCsvImportCore\Model\Table\BcCsvImportJobsTable $jobs */
        $jobs = TableRegistry::getTableLocator()->get('BcCsvImportCore.BcCsvImportJobs');

        $expiredJobs = $jobs->find()
            ->where(['expires_at <' => new DateTime()])
            ->all();

        $total = $expiredJobs->count();

        if ($total === 0) {
            $io->out('削除対象のジョブはありませんでした。');
            return self::CODE_SUCCESS;
        }

        if ($dryRun) {
            $io->out(sprintf('[dry-run] 削除対象のジョブ: %d 件', $total));
            return self::CODE_SUCCESS;
        }

        $deletedFiles = 0;
        $deletedJobs  = 0;

        foreach ($expiredJobs as $job) {
            if (!empty($job->csv_path) && file_exists($job->csv_path)) {
                unlink($job->csv_path);
                $deletedFiles++;
            }
            if (!empty($job->error_log_path) && file_exists($job->error_log_path)) {
                unlink($job->error_log_path);
                $deletedFiles++;
            }

            if ($jobs->delete($job)) {
                $deletedJobs++;
            }
        }

        Log::info(sprintf(
            '[BcCsvImportCore] cleanup done. deleted_jobs=%d deleted_files=%d',
            $deletedJobs,
            $deletedFiles
        ), 'csv_import');

        $io->out(sprintf(
            'クリーンアップ完了: ジョブ %d 件・ファイル %d 件を削除しました。',
            $deletedJobs,
            $deletedFiles
        ));

        return self::CODE_SUCCESS;
    }
}
