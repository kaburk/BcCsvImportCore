<?php
/**
 * CSVインポート画面
 *
 * @var \Cake\View\View $this
 * @var array $pendingJobs
 * @var array $historyJobs
 */
$this->BcAdmin->setTitle($pageTitle ?? __d('baser_core', 'CSVインポート'));

$adminBase = $adminBase ?? '/baser/admin/bc-csv-import-core/csv_imports';
$batchSize = \Cake\Core\Configure::read('BcCsvImportCore.batchSize', 1000);
$csrfToken = $this->request->getAttribute('csrfToken');
?>

<link rel="stylesheet" href="/bc_csv_import_core/css/admin/csv_import.css">

<?php if (!empty($pendingJobs)): ?>
    <section class="bca-section" data-bca-section-type="form-group" id="js-pending-section">
        <h2 class="bca-main__heading" data-bca-heading-size="lg"><?= __d('baser_core', '未完了のインポート') ?></h2>
        <div class="bc-csv-import-core__scroll-table">
        <table class="bca-table-listup">
            <thead class="bca-table-listup__thead">
            <tr>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', '作成日時') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', '状態') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', 'モード') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', '進捗') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', '概要') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', '操作') ?></th>
            </tr>
            </thead>
            <tbody class="bca-table-listup__tbody">
            <?php foreach ($pendingJobs as $job): ?>
                <tr>
                    <td class="bca-table-listup__tbody-td"><?= h($job->created) ?></td>
                    <td class="bca-table-listup__tbody-td"><?= h($job->summary_label ?? '') ?></td>
                    <td class="bca-table-listup__tbody-td"><?= h($job->mode === 'strict' ? '事前確認' : 'スキップ') ?></td>
                    <td class="bca-table-listup__tbody-td"><?= number_format((int)$job->processed) ?>/<?= number_format((int)$job->total) ?></td>
                    <td class="bca-table-listup__tbody-td">
                        <?php if (!empty($job->error_preview)): ?>
                            <?php foreach ($job->error_preview as $preview): ?>
                                <div class="bc-csv-import-core__summary-item"><?= h($preview) ?></div>
                            <?php endforeach; ?>
                        <?php elseif ((int)$job->error_count > 0): ?>
                            <div class="bc-csv-import-core__summary-item"><?= __d('baser_core', 'エラー詳細はCSVをダウンロードして確認してください。') ?></div>
                        <?php else: ?>
                            <div class="bc-csv-import-core__summary-item"><?= __d('baser_core', '再開可能なジョブです。') ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="bca-table-listup__tbody-td bca-table-listup__tbody-td--actions">
                        <?php if (!empty($job->can_resume)): ?>
                            <button class="bca-btn bca-actions__item js-resume-btn"
                                    data-token="<?= h($job->job_token) ?>"
                                    data-total="<?= h($job->total) ?>"
                                    data-processed="<?= h($job->processed) ?>"
                                    data-phase="<?= h($job->phase) ?>"
                                    data-mode="<?= h($job->mode) ?>"
                                    data-import-strategy="<?= h($job->import_strategy ?? 'append') ?>"
                                    data-target-cleared="<?= !empty($job->target_cleared) ? '1' : '0' ?>">
                                <?= __d('baser_core', '再開') ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($job->can_download_errors)): ?>
                            <a href="<?= h($adminBase) ?>/download_errors/<?= h($job->job_token) ?>" class="bca-btn bca-actions__item">
                                <?= __d('baser_core', 'エラーCSV') ?>
                            </a>
                        <?php endif; ?>
                        <button class="bca-btn bca-actions__item js-delete-btn"
                                data-token="<?= h($job->job_token) ?>"
                                data-status="<?= h($job->status) ?>">
                            <?= __d('baser_core', '削除') ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>

<section class="bca-section" data-bca-section-type="form-group" id="js-upload-section">
    <h2 class="bca-main__heading" data-bca-heading-size="lg"><?= __d('baser_core', '新規インポート') ?></h2>
<?php
    // コントローラーの resolveUiSettings() から渡される view 変数を使用する
    // 未設定時のフォールバックのみここで定義
    $showOptionSection    = $showOptionSection    ?? true;
    $showEncoding         = $showEncoding         ?? true;
    $showMode             = $showMode             ?? true;
    $showImportStrategy   = $showImportStrategy   ?? true;
    $showDuplicate        = $showDuplicate        ?? true;
    $defaultEncoding      = $defaultEncoding      ?? 'auto';
    $defaultMode          = $defaultMode          ?? 'strict';
    $defaultImportStrategy = $defaultImportStrategy ?? 'append';
    $defaultDuplicate     = $defaultDuplicate     ?? 'skip';
    $encodingLabels  = ['auto' => '自動判別（推奨）', 'UTF-8' => 'UTF-8', 'Shift-JIS' => 'Shift-JIS'];
    $modeLabels      = ['strict' => '事前確認モード', 'lenient' => 'スキップモード'];
    $strategyLabels  = ['append' => '追記', 'replace' => '全件入れ替え'];
    $duplicateLabels = ['skip' => 'スキップ', 'overwrite' => '上書き', 'error' => 'エラー'];
?>
    <table class="form-table bca-form-table" data-bca-table-type="type2">
        <tbody>
        <tr>
            <th class="col-head bca-form-table__label">
              <?= $this->BcAdminForm->label('csv_file', __d('baser_core', 'CSVファイル') . ' <span class="bca-label" data-bca-label-type="required">' . __d('baser_core', '必須') . '</span>', ['escape' => false]) ?>
            </th>
            <td class="col-input bca-form-table__input">
                <input type="file" id="csv-file" accept=".csv" class="bca-input__file">
                <p class="bca-form__note">
                    <?= __d('baser_core', 'UTF-8 または Shift-JIS 形式のCSVファイルをアップロードしてください。') ?>
                </p>
            </td>
        </tr>
        <tr>
            <th class="col-head bca-form-table__label">
              <?= __d('baser_core', 'テンプレート') ?>
            </th>
            <td class="col-input bca-form-table__input">
                <p class="bca-form__note">
                    <a href="<?= h($adminBase) ?>/download_template" class="bca-btn bca-actions__item" data-bca-btn-type="download" download>
                        <?= __d('baser_core', 'テンプレートCSVダウンロード') ?>
                    </a>
                </p>
                <p class="bca-form__note">
                    <?= __d('baser_core', 'インポート用CSVのフォーマット（列の順序・項目名）を確認できるテンプレートファイルです。') ?>
                    <br>
                    <?= __d('baser_core', 'はじめてインポートする場合はこちらをご利用ください。') ?>
                </p>
            </td>
        </tr>
        </tbody>
    </table>

    <?php if ($showOptionSection): ?>
        <div class="bca-collapse__action">
            <button type="button"
                    class="bca-collapse__btn"
                    data-bca-collapse="collapse"
                    data-bca-target="#csvImportOptionBody"
                    aria-expanded="true"
                    aria-controls="csvImportOptionBody">
                <?= __d('baser_core', 'オプション') ?>&nbsp;&nbsp;
                <i class="bca-icon--chevron-down bca-collapse__btn-icon"></i>
            </button>
        </div>
        <div class="bca-collapse" id="csvImportOptionBody" data-bca-state="" style="display:none;">
            <table class="form-table bca-form-table" data-bca-table-type="type2">
                <tbody>
                <tr>
                    <th class="col-head bca-form-table__label">
                          <?= $this->BcAdminForm->label('encoding', __d('baser_core', '文字コード')) ?>
                    </th>
                    <td class="col-input bca-form-table__input">
                        <?php if ($showEncoding): ?>
                                <?= $this->BcAdminForm->control('encoding', [
                                    'type' => 'select',
                                    'id' => 'encoding',
                                    'label' => false,
                                    'options' => ['auto' => __d('baser_core', '自動判別（推奨）'), 'UTF-8' => 'UTF-8', 'Shift-JIS' => 'Shift-JIS'],
                                    'value' => $defaultEncoding,
                                    'empty' => false,
                                ]) ?>
                        <?php else: ?>
                                <?= $this->BcAdminForm->hidden('encoding', ['value' => $defaultEncoding, 'id' => 'encoding']) ?>
                            <span class="bca-badge"><?= h($encodingLabels[$defaultEncoding] ?? $defaultEncoding) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th class="col-head bca-form-table__label">
                          <?= $this->BcAdminForm->label('mode', __d('baser_core', 'バリデーションモード')) ?>
                    </th>
                    <td class="col-input bca-form-table__input">
                        <?php if ($showMode): ?>
                                <?= $this->BcAdminForm->control('mode', [
                                    'type' => 'radio',
                                    'options' => [
                                        'strict' => __d('baser_core', '事前確認モード（全件バリデーション → エラー0件なら登録）'),
                                        'lenient' => __d('baser_core', 'スキップモード（エラー行を飛ばして登録）'),
                                    ],
                                    'value' => $defaultMode,
                                ]) ?>
                        <?php else: ?>
                                <?= $this->BcAdminForm->hidden('mode', ['value' => $defaultMode]) ?>
                            <span class="bca-badge"><?= h($modeLabels[$defaultMode] ?? $defaultMode) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th class="col-head bca-form-table__label">
                          <?= $this->BcAdminForm->label('import_strategy', __d('baser_core', 'インポート方式')) ?>
                    </th>
                    <td class="col-input bca-form-table__input">
                        <?php if ($showImportStrategy): ?>
                                <?= $this->BcAdminForm->control('import_strategy', [
                                    'type' => 'select',
                                    'id' => 'import-strategy',
                                    'label' => false,
                                    'options' => [
                                        'append' => __d('baser_core', '追記（既存データを残す）'),
                                        'replace' => __d('baser_core', '全件入れ替え（登録直前に既存データを全削除）'),
                                    ],
                                    'value' => $defaultImportStrategy,
                                    'empty' => false,
                                ]) ?>
                            <p class="bca-form__note"><?= __d('baser_core', '全件入れ替えは破壊的操作です。strict では検証エラー時に削除されません。') ?></p>
                        <?php else: ?>
                                <?= $this->BcAdminForm->hidden('import_strategy', ['value' => $defaultImportStrategy, 'id' => 'import-strategy']) ?>
                            <span class="bca-badge"><?= h($strategyLabels[$defaultImportStrategy] ?? $defaultImportStrategy) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th class="col-head bca-form-table__label">
                          <?= $this->BcAdminForm->label('duplicate_mode', __d('baser_core', '重複データの処理')) ?>
                    </th>
                    <td class="col-input bca-form-table__input">
                        <?php if ($showDuplicate): ?>
                                <?= $this->BcAdminForm->control('duplicate_mode', [
                                    'type' => 'select',
                                    'id' => 'duplicate-mode',
                                    'label' => false,
                                    'options' => [
                                        'skip' => __d('baser_core', 'スキップ（既存データを変更しない）'),
                                        'overwrite' => __d('baser_core', '上書き（既存データを更新する）'),
                                        'error' => __d('baser_core', 'エラー（重複をエラーとして報告する）'),
                                    ],
                                    'value' => $defaultDuplicate,
                                    'empty' => false,
                                ]) ?>
                        <?php else: ?>
                                <?= $this->BcAdminForm->hidden('duplicate_mode', ['value' => $defaultDuplicate, 'id' => 'duplicate-mode']) ?>
                            <span class="bca-badge"><?= h($duplicateLabels[$defaultDuplicate] ?? $defaultDuplicate) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?= $this->BcAdminForm->hidden('encoding', ['value' => $defaultEncoding, 'id' => 'encoding']) ?>
        <?= $this->BcAdminForm->hidden('mode', ['value' => $defaultMode]) ?>
        <?= $this->BcAdminForm->hidden('import_strategy', ['value' => $defaultImportStrategy, 'id' => 'import-strategy']) ?>
        <?= $this->BcAdminForm->hidden('duplicate_mode', ['value' => $defaultDuplicate, 'id' => 'duplicate-mode']) ?>
    <?php endif; ?>

    <?php if (!empty($historyJobs)): ?>
        <div class="bca-collapse__action">
            <button type="button"
                    class="bca-collapse__btn"
                    data-bca-collapse="collapse"
                    data-bca-target="#csvImportHistoryBody"
                    aria-expanded="false"
                    aria-controls="csvImportHistoryBody">
                <?= __d('baser_core', '最近のジョブ履歴') ?>&nbsp;&nbsp;
                <i class="bca-icon--chevron-down bca-collapse__btn-icon"></i>
            </button>
        </div>
        <div class="bca-collapse" id="csvImportHistoryBody" data-bca-state="" style="display:none;">
            <section class="bca-section" data-bca-section-type="form-group" id="js-history-section">
                <div class="bc-csv-import-core__scroll-table">
                <table class="bca-table-listup" id="js-history-table">
                    <thead class="bca-table-listup__thead">
                    <tr>
                        <th class="bca-table-listup__thead-th" style="width:2.5rem;">
                            <input type="checkbox" id="js-history-check-all" title="<?= __d('baser_core', 'すべて選択') ?>">
                        </th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', '作成日時') ?></th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', '終了日時') ?></th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', '状態') ?></th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', 'モード') ?></th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', '結果') ?></th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', '概要') ?></th>
                        <th class="bca-table-listup__thead-th"><?= __d('baser_core', '操作') ?></th>
                    </tr>
                    </thead>
                    <tbody class="bca-table-listup__tbody" id="js-history-tbody">
                    <?php foreach ($historyJobs as $job): ?>
                        <tr data-job-token="<?= h($job->job_token) ?>">
                            <td class="bca-table-listup__tbody-td">
                                <input type="checkbox" class="js-history-check" value="<?= h($job->job_token) ?>">
                            </td>
                            <td class="bca-table-listup__tbody-td"><?= h($job->created) ?></td>
                            <td class="bca-table-listup__tbody-td"><?= h($job->ended_at ?? $job->modified ?? '-') ?></td>
                            <td class="bca-table-listup__tbody-td"><?= h($job->summary_label ?? '') ?></td>
                            <td class="bca-table-listup__tbody-td"><?= h($job->mode === 'strict' ? '事前確認' : 'スキップ') ?></td>
                            <td class="bca-table-listup__tbody-td"><?= h($job->detail_label ?? '') ?></td>
                            <td class="bca-table-listup__tbody-td">
                                <?php if (!empty($job->error_preview)): ?>
                                    <?php foreach ($job->error_preview as $preview): ?>
                                        <div class="bc-csv-import-core__summary-item"><?= h($preview) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="bc-csv-import-core__summary-item"><?= __d('baser_core', 'エラーは記録されていません。') ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="bca-table-listup__tbody-td bca-table-listup__tbody-td--actions">
                                <?php if (!empty($job->can_download_errors)): ?>
                                    <a href="<?= h($adminBase) ?>/download_errors/<?= h($job->job_token) ?>" class="bca-btn bca-actions__item">
                                        <?= __d('baser_core', 'エラーCSV') ?>
                                    </a>
                                <?php endif; ?>
                                <button class="bca-btn bca-actions__item js-delete-btn"
                                        data-token="<?= h($job->job_token) ?>"
                                        data-status="<?= h($job->status) ?>">
                                    <?= __d('baser_core', '削除') ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="bca-actions" id="js-history-bulk-actions">
                    <div class="bca-actions__before"></div>
                    <div class="bca-actions__main">
                        <button type="button" id="js-history-delete-all-btn" class="bca-btn bca-actions__item" data-bca-btn-type="delete" disabled>
                            <?= __d('baser_core', '選択した履歴を削除') ?>
                        </button>
                    </div>
                    <div class="bca-actions__sub"></div>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <div id="js-upload-error" class="bc-csv-import-core__upload-error" style="display:none;"></div>

    <div class="bca-actions">
        <div class="bca-actions__before"></div>
        <div class="bca-actions__main">
            <button id="js-start-btn" class="bca-btn bca-actions__item" data-bca-btn-type="save" data-bca-btn-size="lg" data-bca-btn-width="lg">
                <?= __d('baser_core', 'アップロード開始') ?>
            </button>
        </div>
        <div class="bca-actions__sub"></div>
    </div>
</section>

<!-- 処理中エリア -->
<section class="bca-section" data-bca-section-type="form-group" id="js-progress-section" style="display:none;">
    <h2 class="bca-main__heading" data-bca-heading-size="lg" id="js-progress-title"><?= __d('baser_core', '処理中...') ?></h2>
    <div class="bca-progress">
        <div class="bca-progress__bar bc-csv-import-core__progress-bar" id="js-progress-bar" style="width:0%;"></div>
    </div>
    <p id="js-progress-text"><?= __d('baser_core', '0 / 0 件') ?></p>
    <p id="js-error-text" class="bc-csv-import-core__error-text"></p>
    <div class="bca-actions">
        <div class="bca-actions__before"></div>
        <div class="bca-actions__main">
            <button id="js-cancel-btn" class="bca-btn bca-actions__item" data-bca-btn-color="danger">
                <?= __d('baser_core', 'キャンセル') ?>
            </button>
        </div>
        <div class="bca-actions__sub"></div>
    </div>
</section>

<!-- 結果エリア -->
<section class="bca-section" data-bca-section-type="form-group" id="js-result-section" style="display:none;">
    <h2 class="bca-main__heading" data-bca-heading-size="lg"><?= __d('baser_core', '処理結果') ?></h2>
    <table class="form-table bca-form-table" id="js-result-table" data-bca-table-type="type2">
        <tbody>
        <tr><th class="col-head bca-form-table__label"><?= __d('baser_core', '処理件数') ?></th><td class="col-input bca-form-table__input" id="js-res-total">-</td></tr>
        <tr><th class="col-head bca-form-table__label"><?= __d('baser_core', '成功件数') ?></th><td class="col-input bca-form-table__input" id="js-res-success">-</td></tr>
        <tr id="js-dup-row" style="display:none;"><th class="col-head bca-form-table__label"><?= __d('baser_core', '重複スキップ件数') ?></th><td class="col-input bca-form-table__input" id="js-res-dup">-</td></tr>
        <tr id="js-error-count-row" style="display:none;"><th class="col-head bca-form-table__label" style="color:#c33;"><?= __d('baser_core', 'エラー件数') ?></th><td class="col-input bca-form-table__input" id="js-res-error" style="color:#c33;">-</td></tr>
        <tr><th class="col-head bca-form-table__label"><?= __d('baser_core', '処理時間') ?></th><td class="col-input bca-form-table__input" id="js-res-time">-</td></tr>
        </tbody>
    </table>

    <div id="js-error-list-area" style="display:none;">
        <h3 class="bca-main__heading" data-bca-heading-size="md"><?= __d('baser_core', 'エラー一覧') ?></h3>
        <div class="bc-csv-import-core__scroll-table">
        <table class="bca-table-listup">
            <thead class="bca-table-listup__thead">
            <tr>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', '行番号') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', 'フィールド') ?></th>
                <th class="bca-table-listup__thead-th"><?= __d('baser_core', 'エラー内容') ?></th>
            </tr>
            </thead>
            <tbody id="js-error-list" class="bca-table-listup__tbody"></tbody>
        </table>
        </div>
        <div class="bca-actions">
            <div class="bca-actions__before"></div>
            <div class="bca-actions__main">
                <a id="js-download-errors" href="#" class="bca-btn bca-actions__item" data-bca-btn-type="download">
                    <?= __d('baser_core', 'エラーCSVダウンロード') ?>
                </a>
            </div>
            <div class="bca-actions__sub"></div>
        </div>
    </div>

    <div class="bca-actions">
        <div class="bca-actions__before"></div>
        <div class="bca-actions__main">
            <button id="js-restart-btn" class="bca-btn bca-actions__item"><?= __d('baser_core', '新しいインポートを開始') ?></button>
        </div>
        <div class="bca-actions__sub"></div>
    </div>
</section>

<div id="js-csv-import-config"
     hidden
     data-admin-base="<?= h($adminBase) ?>"
     data-csrf-token="<?= h($csrfToken) ?>"
     data-batch-size="<?= (int)$batchSize ?>"></div>
<script src="/bc_csv_import_core/js/admin/csv_import.js"></script>
