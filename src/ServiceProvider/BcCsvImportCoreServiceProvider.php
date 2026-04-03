<?php

namespace BcCsvImportCore\ServiceProvider;

use Cake\Core\ServiceProvider;

/**
 * BcCsvImportCoreServiceProvider
 *
 * サービスバインドは各サブプラグインのコントローラーが直接インスタンス化するため、
 * コア側では何も登録しない。
 */
class BcCsvImportCoreServiceProvider extends ServiceProvider
{

    protected array $provides = [];

    public function services($container): void {}

}
