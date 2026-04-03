<?php

namespace BcCsvImportCore\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * BcCsvImportJobsTable
 */
class BcCsvImportJobsTable extends Table
{

    /**
     * Initialize
     *
     * @param array $config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('bc_csv_import_jobs');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /**
     * Validation Default
     *
     * @param Validator $validator
     * @return Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('job_token');
        $validator->maxLength('job_token', 255);
        $validator->inList('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'], null, true);
        $validator->inList('mode', ['strict', 'lenient'], null, true);
        $validator->inList('import_strategy', ['append', 'replace'], null, true);
        $validator->inList('duplicate_mode', ['skip', 'overwrite', 'error'], null, true);
        $validator->inList('phase', ['validate', 'import'], null, true);
        $validator->boolean('target_cleared');

        return $validator;
    }

}
