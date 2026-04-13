<?php
declare(strict_types=1);

namespace BcCsvImportCore\Test\TestCase\Command;

use BaserCore\TestSuite\BcTestCase;
use BcCsvImportCore\Command\CleanupCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * CleanupCommandTest
 *
 * BcCsvImportCore.cleanup コマンドのテスト。
 * 期限切れジョブの削除・ドライランオプションを検証する。
 */
class CleanupCommandTest extends BcTestCase
{
    use ConsoleIntegrationTestTrait;

    /** @var \BcCsvImportCore\Model\Table\BcCsvImportJobsTable */
    private $Jobs;

    /** @var string テスト用一時ディレクトリ */
    private string $tmpDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->Jobs = TableRegistry::getTableLocator()->get('BcCsvImportCore.BcCsvImportJobs');
        $this->tmpDir = TMP . 'test_cleanup_' . uniqid() . DS;
        mkdir($this->tmpDir, 0777, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmpDir . '*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testExecuteDeletesExpiredJobs(): void
    {
        // 期限切れジョブを作成
        $csvPath   = $this->tmpDir . 'expired.csv';
        $errorPath = $this->tmpDir . 'expired_errors.jsonl';
        file_put_contents($csvPath, "col\nvalue\n");
        file_put_contents($errorPath, '');

        $job = $this->Jobs->newEntity([
            'job_token'      => 'test-expired-token',
            'target_table'   => 'dummy',
            'phase'          => 'validate',
            'total'          => 1,
            'processed'      => 0,
            'success_count'  => 0,
            'error_count'    => 0,
            'skip_count'     => 0,
            'status'         => 'completed',
            'mode'           => 'strict',
            'import_strategy'=> 'append',
            'duplicate_mode' => 'skip',
            'csv_path'       => $csvPath,
            'error_log_path' => $errorPath,
            'target_cleared' => false,
            'expires_at'     => new DateTime('-1 day'), // 期限切れ
        ]);
        $this->Jobs->saveOrFail($job);

        $this->exec('BcCsvImportCore.cleanup');

        $this->assertExitSuccess();
        $this->assertOutputContains('クリーンアップ完了');

        // Jobレコードが削除されていること
        $remaining = $this->Jobs->find()->where(['job_token' => 'test-expired-token'])->count();
        $this->assertSame(0, $remaining);

        // ファイルが削除されていること
        $this->assertFileDoesNotExist($csvPath);
        $this->assertFileDoesNotExist($errorPath);
    }

    public function testExecuteSkipsActiveJobs(): void
    {
        // 期限内（有効）のジョブ
        $job = $this->Jobs->newEntity([
            'job_token'      => 'test-active-token',
            'target_table'   => 'dummy',
            'phase'          => 'import',
            'total'          => 1,
            'processed'      => 0,
            'success_count'  => 0,
            'error_count'    => 0,
            'skip_count'     => 0,
            'status'         => 'pending',
            'mode'           => 'strict',
            'import_strategy'=> 'append',
            'duplicate_mode' => 'skip',
            'csv_path'       => null,
            'error_log_path' => null,
            'target_cleared' => false,
            'expires_at'     => new DateTime('+3 days'), // 有効
        ]);
        $this->Jobs->saveOrFail($job);

        $this->exec('BcCsvImportCore.cleanup');

        $this->assertExitSuccess();

        // Jobレコードが残っていること
        $remaining = $this->Jobs->find()->where(['job_token' => 'test-active-token'])->count();
        $this->assertSame(1, $remaining);

        // 後片付け
        $this->Jobs->deleteAll(['job_token' => 'test-active-token']);
    }

    public function testDryRunOptionDoesNotDelete(): void
    {
        // 期限切れジョブを作成
        $job = $this->Jobs->newEntity([
            'job_token'      => 'test-dryrun-token',
            'target_table'   => 'dummy',
            'phase'          => 'validate',
            'total'          => 1,
            'processed'      => 0,
            'success_count'  => 0,
            'error_count'    => 0,
            'skip_count'     => 0,
            'status'         => 'completed',
            'mode'           => 'strict',
            'import_strategy'=> 'append',
            'duplicate_mode' => 'skip',
            'csv_path'       => null,
            'error_log_path' => null,
            'target_cleared' => false,
            'expires_at'     => new DateTime('-1 day'),
        ]);
        $this->Jobs->saveOrFail($job);

        $this->exec('BcCsvImportCore.cleanup --dry-run');

        $this->assertExitSuccess();
        $this->assertOutputContains('[dry-run]');

        // Jobレコードが残っていること（削除されない）
        $remaining = $this->Jobs->find()->where(['job_token' => 'test-dryrun-token'])->count();
        $this->assertSame(1, $remaining);

        // 後片付け
        $this->Jobs->deleteAll(['job_token' => 'test-dryrun-token']);
    }

    public function testExecuteWhenNoExpiredJobs(): void
    {
        // 期限切れジョブが0件の場合
        $this->exec('BcCsvImportCore.cleanup');

        $this->assertExitSuccess();
        $this->assertOutputContains('削除対象のジョブはありませんでした');
    }
}
