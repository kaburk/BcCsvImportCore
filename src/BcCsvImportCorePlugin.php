<?php
declare(strict_types=1);


namespace BcCsvImportCore;

use BaserCore\BcPlugin;
use BcCsvImportCore\Command\CleanupCommand;
use BcCsvImportCore\ServiceProvider\BcCsvImportCoreServiceProvider;
use Cake\Console\CommandCollection;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Log\Log;

/**
 * plugin for BcCsvImportCore
 */
class BcCsvImportCorePlugin extends BcPlugin
{

    /**
     * bootstrap
     *
     * csv_import ログチャネルを設定し、logs/csv_import.log へ出力する
     *
     * @param PluginApplicationInterface $app
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (!in_array('csv_import', Log::configured(), true)) {
            Log::setConfig('csv_import', [
                'className' => 'File',
                'path' => LOGS,
                'file' => 'csv_import',
                'levels' => ['info', 'warning', 'error'],
                'scopes' => ['csv_import'],
            ]);
        }

        $tmpDir = TMP . 'csv_imports' . DS;
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
    }

    /**
     * console
     *
     * @param CommandCollection $commands
     * @return CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('BcCsvImportCore.cleanup', CleanupCommand::class);
        return $commands;
    }

    /**
     * services
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        $container->addServiceProvider(new BcCsvImportCoreServiceProvider());
    }

}
