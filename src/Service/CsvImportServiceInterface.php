<?php

namespace BcCsvImportCore\Service;

use BcCsvImportCore\Model\Entity\BcCsvImportJob;
use Cake\Datasource\EntityInterface;
use Psr\Http\Message\StreamInterface;

/**
 * CsvImportServiceInterface
 */
interface CsvImportServiceInterface
{

    /**
     * インポート対象のテーブル名を返す
     *
     * @return string
     */
    public function getTableName(): string;

    /**
     * CSVカラムマップを返す
     * [CSVヘッダキー => ['label' => '表示名', 'required' => true/false]]
     *
     * @return array
     */
    public function getColumnMap(): array;

    /**
     * 重複チェックに使うカラム名を返す
     *
     * @return string
     */
    public function getDuplicateKey(): string;

    /**
     * CSV1行（連想配列）からEntityを生成する
     *
     * @param array $row
     * @return EntityInterface
     */
    public function buildEntity(array $row): EntityInterface;

    /**
     * ジョブを作成する
     *
     * @param string $csvPath
     * @param array $options
     * @return BcCsvImportJob
     */
    public function createJob(string $csvPath, array $options = []): BcCsvImportJob;

    /**
     * バリデーションのバッチ処理（1000件ずつ）
     *
     * @param string $token
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function validateBatch(string $token, int $offset, int $limit): array;

    /**
     * 登録バッチ処理（1000件ずつ）
     *
     * @param string $token
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function processBatch(string $token, int $offset, int $limit): array;

    /**
     * ジョブのステータスを取得する
     *
     * @param string $token
     * @return array
     */
    public function getJobStatus(string $token): array;

    /**
     * ジョブをキャンセルする
     *
     * @param string $token
     * @return bool
     */
    public function cancelJob(string $token): bool;

    /**
     * ジョブを削除する
     *
     * @param string $token
     * @return bool
     */
    public function deleteJob(string $token): bool;

    /**
     * テンプレートCSVの内容を生成する
     *
     * @return string
     */
    public function buildTemplateCsv(): string;

    /**
     * エラーCSVの内容を生成する
     *
     * @param string $token
     * @return string
     */
    public function buildErrorCsv(string $token): string;

    /**
     * エラーCSVの内容をストリームとして生成する
     *
     * 大量エラー時のメモリ消費を抑えるため、逐次出力でレスポンスを返す。
     *
     * @param string $token
     * @return StreamInterface
     */
    public function buildErrorCsvStream(string $token): StreamInterface;

    /**
     * 期限切れの一時ファイルを削除する
     *
     * @return int 削除件数
     */
    public function cleanupExpiredFiles(): int;

    /**
     * 文字コードを自動判別する
     *
     * @param string $csvPath
     * @return string
     */
    public function detectEncoding(string $csvPath): string;

    /**
     * CSVをUTF-8に変換して保存する
     *
     * @param string $csvPath
     * @param string $fromEncoding
     * @return void
     */
    public function convertCsvEncoding(string $csvPath, string $fromEncoding): void;

}
