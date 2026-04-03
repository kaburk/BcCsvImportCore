<?php
declare(strict_types=1);

use BaserCore\Database\Migration\BcMigration;

class DropLegacyErrorLogFromBcCsvImportJobs extends BcMigration
{
    public function up()
    {
        $table = $this->table('bc_csv_import_jobs');
        if ($table->hasColumn('error_log')) {
            $table
                ->removeColumn('error_log')
                ->update();
        }
    }

    public function down()
    {
        $table = $this->table('bc_csv_import_jobs');
        if (!$table->hasColumn('error_log')) {
            $table
                ->addColumn('error_log', 'text', ['null' => true, 'default' => null])
                ->update();
        }
    }
}
