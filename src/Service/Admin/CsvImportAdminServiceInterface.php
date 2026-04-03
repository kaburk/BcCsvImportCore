<?php

namespace BcCsvImportCore\Service\Admin;

/**
 * CsvImportAdminServiceInterface
 */
interface CsvImportAdminServiceInterface
{

    /**
     * 一覧対象の target_table を設定する
     *
     * @param string $targetTable
     * @return void
     */
    public function setTargetTable(string $targetTable): void;

    /**
     * アップロード画面用の View 変数を取得する
     *
     * @return array
     */
    public function getViewVarsForIndex(): array;

    /**
     * ジョブの概要を取得する
     *
     * @param string $token
     * @return array
     */
    public function getJobSummary(string $token): array;

}
