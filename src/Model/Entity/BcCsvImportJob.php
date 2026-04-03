<?php

namespace BcCsvImportCore\Model\Entity;

use Cake\ORM\Entity;

/**
 * BcCsvImportJob Entity
 *
 * @property int $id
 * @property string $job_token
 * @property string|null $job_meta
 * @property string $target_table
 * @property string $phase
 * @property int $total
 * @property int $processed
 * @property int $success_count
 * @property int $error_count
 * @property int $skip_count
 * @property string $status
 * @property string $mode
 * @property string $import_strategy
 * @property string $duplicate_mode
 * @property string $csv_path
 * @property int $validate_position
 * @property int $import_position
 * @property bool $target_cleared
 * @property string $error_log_path
 * @property \Cake\I18n\DateTime $expires_at
 * @property \Cake\I18n\DateTime $started_at
 * @property \Cake\I18n\DateTime $ended_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class BcCsvImportJob extends Entity
{

    /**
     * Accessible
     *
     * @var array
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * 処理完了かどうか
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * 処理中かどうか
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

}
