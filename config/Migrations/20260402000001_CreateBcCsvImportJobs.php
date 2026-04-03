<?php
declare(strict_types=1);

use BaserCore\Database\Migration\BcMigration;

class CreateBcCsvImportJobs extends BcMigration
{
    public function up()
    {
        $this->table('bc_csv_import_jobs', ['collation' => 'utf8mb4_general_ci'])
            ->addColumn('job_token', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('job_meta', 'text', ['null' => true, 'default' => null])
            ->addColumn('target_table', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('phase', 'string', ['limit' => 20, 'null' => true, 'default' => 'validate'])
            ->addColumn('total', 'integer', ['null' => true, 'default' => null])
            ->addColumn('processed', 'integer', ['null' => true, 'default' => 0])
            ->addColumn('success_count', 'integer', ['null' => true, 'default' => 0])
            ->addColumn('error_count', 'integer', ['null' => true, 'default' => 0])
            ->addColumn('skip_count', 'integer', ['null' => true, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => true, 'default' => 'pending'])
            ->addColumn('mode', 'string', ['limit' => 20, 'null' => true, 'default' => 'strict'])
            ->addColumn('import_strategy', 'string', ['limit' => 20, 'null' => true, 'default' => 'append'])
            ->addColumn('duplicate_mode', 'string', ['limit' => 20, 'null' => true, 'default' => 'skip'])
            ->addColumn('csv_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('validate_position', 'biginteger', ['null' => true, 'default' => 0])
            ->addColumn('import_position', 'biginteger', ['null' => true, 'default' => 0])
            ->addColumn('target_cleared', 'boolean', ['null' => true, 'default' => false])
            ->addColumn('error_log_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('expires_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('started_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('ended_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['job_token'], ['unique' => true])
            ->create();
    }

    public function down()
    {
        $this->table('bc_csv_import_jobs')->drop()->save();
    }
}
