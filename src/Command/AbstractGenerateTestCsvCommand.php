<?php
declare(strict_types=1);

namespace BcCsvImportCore\Command;

use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * AbstractGenerateTestCsvCommand
 *
 * テスト用CSVファイルを生成するコンソールコマンドの抽象基底クラス。
 * 各プラグインでこのクラスを継承し、データ生成ロジックだけを実装する。
 *
 * **継承クラスで実装が必要なメソッド:**
 *  - defaultName(): string              コマンド名 (static)
 *  - getCommandDescription(): string    --help に表示する説明文
 *  - getService(): CsvImportServiceInterface  サービスインスタンスを返す
 *  - getFilenamePrefix(): string        出力ファイル名のプレフィックス
 *  - buildRow(int $i, array $columnKeys): array  1行分のデータ生成
 *  - getErrorPatterns(): array          エラーパターンの定義
 */
abstract class AbstractGenerateTestCsvCommand extends Command
{

    /**
     * --help に表示する説明文
     */
    abstract protected function getCommandDescription(): string;

    /**
     * インポートサービスのインスタンスを返す
     */
    abstract protected function getService(): CsvImportServiceInterface;

    /**
     * 出力 CSV ファイル名のプレフィックス
     * 例: 'import_test_', 'import_sample_orders_', 'import_blog_posts_'
     */
    abstract protected function getFilenamePrefix(): string;

    /**
     * 行番号 $i に対応するデータ行を連想配列で返す
     * キーは getService()->getColumnMap() のキーと同じ順・同じセットにすること。
     *
     * @param int $i 1 始まりの行番号
     * @param array<string> $columnKeys getColumnMap() のキー一覧
     * @return array<string, mixed>
     */
    abstract protected function buildRow(int $i, array $columnKeys): array;

    /**
     * エラーパターンの連想配列を返す
     * key: エラー説明文, value: callable(array $row): array
     *
     * @return array<string, callable>
     */
    abstract protected function getErrorPatterns(): array;

    /**
     * オプションパーサーの定義
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription($this->getCommandDescription())
            ->addOption('output', [
                'help' => '出力先ディレクトリ（デフォルト: tmp/csv/）',
                'default' => null,
            ])
            ->addOption('sizes', [
                'help' => '生成サイズをカンマ区切りで指定（k/m サフィックス対応）。例: 1k,10k,500k,1m',
                'default' => '10k',
            ])
            ->addOption('errors', [
                'help' => 'エラーデータを含める割合（%）。0 で含めない。',
                'default' => '0',
            ]);

        return $parser;
    }

    /**
     * コマンド実行
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $outputDir = $args->getOption('output') ?? TMP . 'csv';
        $sizeKeys = explode(',', $args->getOption('sizes') ?? '10k');
        $errorRate = (int)($args->getOption('errors') ?? 0);

        $service = $this->getService();
        $columnMap = $service->getColumnMap();
        $columnKeys = array_keys($columnMap);
        $headers = array_map(fn($col) => $col['label'], $columnMap);

        $errorPatterns = $this->getErrorPatterns();
        $errorPatternKeys = array_keys($errorPatterns);
        $errorPatternCount = count($errorPatterns);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        foreach ($sizeKeys as $key) {
            $key = trim($key);
            $count = $this->parseSizeToCount($key);
            if (!$count || $count < 1) {
                $io->warning("スキップ（不明なサイズ指定）: {$key}");
                continue;
            }

            $suffix = $errorRate > 0 ? "_err{$errorRate}pct" : '';
            $filename = $outputDir . DS . $this->getFilenamePrefix() . $key . $suffix . '.csv';
            $errorInterval = $errorRate > 0 ? (int)round(100 / $errorRate) : 0;
            $errorCount = 0;

            $io->out("生成中: {$filename} ({$count}件" . ($errorRate > 0 ? " / エラー約{$errorRate}%" : '') . ")...");
            $start = microtime(true);

            $fp = fopen($filename, 'w');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers);

            for ($i = 1; $i <= $count; $i++) {
                $row = $this->buildRow($i, $columnKeys);

                if ($errorInterval > 0 && $i % $errorInterval === 0) {
                    $patternKey = $errorPatternKeys[$errorCount % $errorPatternCount];
                    $row = $errorPatterns[$patternKey]($row);
                    $errorCount++;
                }

                fputcsv($fp, array_values($row));

                if ($i % 100000 === 0) {
                    $io->out("  {$i}/{$count} 件...");
                }
            }

            fclose($fp);

            $elapsed = round(microtime(true) - $start, 1);
            $size = round(filesize($filename) / 1024 / 1024, 1);
            $io->success("  完了: {$elapsed}秒 / {$size}MB" . ($errorRate > 0 ? " （エラー行: {$errorCount}件）" : ''));
        }

        $io->out("\n全ファイル生成完了。出力先: {$outputDir}");
        return self::CODE_SUCCESS;
    }

    /**
     * サイズ文字列をレコード件数に変換
     *
     * @param string $size "10k", "500k", "1m" などの文字列
     * @return int|null
     */
    private function parseSizeToCount(string $size): ?int
    {
        $size = strtolower(trim($size));
        if ($size === '') {
            return null;
        }
        if (preg_match('/^(\d+)(k|m)?$/', $size, $matches)) {
            $count = (int)$matches[1];
            $unit = $matches[2] ?? '';
            if ($unit === 'k') {
                return $count * 1000;
            }
            if ($unit === 'm') {
                return $count * 1000000;
            }
            return $count;
        }
        return null;
    }
}
