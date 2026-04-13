<?php
declare(strict_types=1);

namespace BcCsvImportCore\Test\TestCase\Service;

use BaserCore\TestSuite\BcTestCase;
use BcCsvImportCore\Service\CsvImportService;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Datasource\EntityInterface;
use Cake\ORM\TableRegistry;

/**
 * CsvImportServiceTest
 *
 * CsvImportService の protected/public メソッドを、
 * 最小限のスタブ実装（ConcreteStubCsvImportService）を通じてテストする。
 * DB を必要とするメソッドは setUp() 内で bc_csv_import_jobs テーブルへの
 * マイグレーション済み状態（BcTestCase が保証）を前提とする。
 */
class CsvImportServiceTest extends BcTestCase
{
    /** @var CsvImportService */
    private CsvImportService $service;

    /** @var string テスト用一時ディレクトリ */
    private string $tmpDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new class extends CsvImportService implements CsvImportServiceInterface {
            public function getTableName(): string
            {
                return 'BcCsvImportCore.BcCsvImportJobs'; // ダミー（実際には使わない）
            }

            public function getColumnMap(): array
            {
                return [
                    'name'     => ['label' => '商品名',   'required' => true,  'sample' => 'テスト商品'],
                    'sku'      => ['label' => 'SKU',      'required' => false, 'sample' => 'SKU-001'],
                    'price'    => ['label' => '価格',     'required' => false, 'sample' => '1000'],
                    'category' => ['label' => 'カテゴリ', 'required' => false, 'sample' => 'A'],
                ];
            }

            public function getDuplicateKey(): string
            {
                return 'sku';
            }

            public function buildEntity(array $row): EntityInterface
            {
                return TableRegistry::getTableLocator()
                    ->get('BcCsvImportCore.BcCsvImportJobs')
                    ->newEmptyEntity();
            }
        };

        $this->tmpDir = TMP . 'test_csv_import_' . uniqid() . DS;
        mkdir($this->tmpDir, 0777, true);
    }

    public function tearDown(): void
    {
        // 一時ファイルを全て削除
        foreach (glob($this->tmpDir . '*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // buildTemplateCsv
    // ─────────────────────────────────────────────────────────────

    public function testBuildTemplateCsvReturnsHeaderAndSampleRow(): void
    {
        $csv = $this->service->buildTemplateCsv();

        $lines = array_map('trim', explode("\n", trim($csv)));
        $this->assertCount(2, $lines, 'ヘッダ行とサンプル行の2行が返されること');

        $headerRow = str_getcsv($lines[0]);
        $this->assertSame(['商品名', 'SKU', '価格', 'カテゴリ'], $headerRow);

        $sampleRow = str_getcsv($lines[1]);
        $this->assertSame(['テスト商品', 'SKU-001', '1000', 'A'], $sampleRow);
    }

    // ─────────────────────────────────────────────────────────────
    // validateCsvHeaders
    // ─────────────────────────────────────────────────────────────

    public function testValidateCsvHeadersPassesWithCorrectHeaders(): void
    {
        $csvPath = $this->tmpDir . 'valid.csv';
        $this->writeCsv($csvPath, [
            ['商品名', 'SKU', '価格', 'カテゴリ'],
            ['テスト', 'SKU-001', '500', 'A'],
        ]);

        // 例外が投げられないことを確認
        $this->execPrivateMethod($this->service, 'validateCsvHeaders', [$csvPath]);
        $this->assertTrue(true);
    }

    public function testValidateCsvHeadersThrowsOnMissingColumn(): void
    {
        $csvPath = $this->tmpDir . 'missing.csv';
        $this->writeCsv($csvPath, [
            ['商品名', 'SKU'], // 価格・カテゴリが欠落
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSVのヘッダが一致しません');
        $this->execPrivateMethod($this->service, 'validateCsvHeaders', [$csvPath]);
    }

    public function testValidateCsvHeadersThrowsOnEmptyFile(): void
    {
        $csvPath = $this->tmpDir . 'empty.csv';
        file_put_contents($csvPath, '');

        $this->expectException(\RuntimeException::class);
        $this->execPrivateMethod($this->service, 'validateCsvHeaders', [$csvPath]);
    }

    // ─────────────────────────────────────────────────────────────
    // readCsvBatchByPosition
    // ─────────────────────────────────────────────────────────────

    public function testReadCsvBatchByPositionReturnsFirstBatch(): void
    {
        $csvPath = $this->tmpDir . 'batch.csv';
        $this->writeCsv($csvPath, [
            ['商品名', 'SKU', '価格', 'カテゴリ'],
            ['商品A', 'SKU-001', '100', 'カA'],
            ['商品B', 'SKU-002', '200', 'カB'],
            ['商品C', 'SKU-003', '300', 'カC'],
        ]);

        $result = $this->execPrivateMethod($this->service, 'readCsvBatchByPosition', [$csvPath, 0, 2]);

        $this->assertCount(2, $result['rows'], '2件取得できること');
        $this->assertSame('商品A', $result['rows'][0][0]);
        $this->assertSame('商品B', $result['rows'][1][0]);
        $this->assertGreaterThan(0, $result['next_position']);
    }

    public function testReadCsvBatchByPositionContinuesFromOffset(): void
    {
        $csvPath = $this->tmpDir . 'batch2.csv';
        $this->writeCsv($csvPath, [
            ['商品名', 'SKU', '価格', 'カテゴリ'],
            ['商品A', 'SKU-001', '100', 'カA'],
            ['商品B', 'SKU-002', '200', 'カB'],
            ['商品C', 'SKU-003', '300', 'カC'],
        ]);

        $first  = $this->execPrivateMethod($this->service, 'readCsvBatchByPosition', [$csvPath, 0, 2]);
        $second = $this->execPrivateMethod($this->service, 'readCsvBatchByPosition', [$csvPath, $first['next_position'], 2]);

        $this->assertCount(1, $second['rows'], '残り1件だけ返ること');
        $this->assertSame('商品C', $second['rows'][0][0]);
    }

    public function testReadCsvBatchByPositionSkipsEmptyRows(): void
    {
        $csvPath = $this->tmpDir . 'empty_rows.csv';
        // 空行を含む CSV
        file_put_contents($csvPath, "商品名,SKU,価格,カテゴリ\n商品A,SKU-001,100,カA\n\n商品B,SKU-002,200,カB\n");

        $result = $this->execPrivateMethod($this->service, 'readCsvBatchByPosition', [$csvPath, 0, 10]);
        $this->assertCount(2, $result['rows'], '空行はスキップされること');
    }

    // ─────────────────────────────────────────────────────────────
    // detectEncoding / convertCsvEncoding
    // ─────────────────────────────────────────────────────────────

    public function testDetectEncodingReturnUtf8ForUtf8File(): void
    {
        $csvPath = $this->tmpDir . 'utf8.csv';
        file_put_contents($csvPath, "商品名,SKU\nテスト,SKU-001\n");

        $encoding = $this->service->detectEncoding($csvPath);
        $this->assertSame('UTF-8', $encoding);
    }

    public function testConvertCsvEncodingIsNoopForUtf8(): void
    {
        $csvPath = $this->tmpDir . 'utf8_noop.csv';
        $original = "商品名,SKU\nテスト,SKU-001\n";
        file_put_contents($csvPath, $original);

        $this->service->convertCsvEncoding($csvPath, 'UTF-8');
        $this->assertSame($original, file_get_contents($csvPath));
    }

    public function testConvertCsvEncodingStripsUtf8Bom(): void
    {
        $csvPath = $this->tmpDir . 'bom.csv';
        // UTF-8 BOM 付き
        file_put_contents($csvPath, "\xEF\xBB\xBF" . "商品名,SKU\n");

        $this->service->convertCsvEncoding($csvPath, 'UTF-8');
        $content = file_get_contents($csvPath);
        $this->assertStringStartsWith('商品名', $content, 'BOM が除去されること');
    }

    // ─────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────

    private function writeCsv(string $path, array $rows): void
    {
        $fp = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }
}
