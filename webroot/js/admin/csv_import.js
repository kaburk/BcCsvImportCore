(function () {
    function initialize() {
        const config = document.getElementById('js-csv-import-config');
        if (!config) {
            return;
        }

        const adminBase = config.dataset.adminBase || '';
        const csrfToken = config.dataset.csrfToken || '';
        const batchSize = Number(config.dataset.batchSize || 1000);
        const blogContentSelect = document.getElementById('js-blog-content-id');
        const downloadPostsButton = document.getElementById('js-download-posts-btn');

        let currentToken = null;
        let cancelled = false;
        let startTime = null;

        async function apiPost(url, data) {
            const body = new FormData();
            for (const [key, value] of Object.entries(data)) {
                body.append(key, value);
            }
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body,
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                const text = await response.text();
                throw new Error('Server error (' + response.status + '): ' + text.substring(0, 200));
            }
            return response.json();
        }

        function formatNumber(value) {
            return Number(value || 0).toLocaleString('ja-JP');
        }

        function setProgressPhase(phase) {
            const progressBar = document.getElementById('js-progress-bar');
            progressBar.classList.remove('bc-csv-import-core__progress-bar--validate', 'bc-csv-import-core__progress-bar--import');
            progressBar.classList.add(
                phase === 'validate'
                    ? 'bc-csv-import-core__progress-bar--validate'
                    : 'bc-csv-import-core__progress-bar--import'
            );
        }

        function updateProgress(processed, total, phase) {
            const percentage = total > 0 ? Math.round(processed / total * 100) : 0;
            setProgressPhase(phase);
            document.getElementById('js-progress-bar').style.width = percentage + '%';
            const phaseLabel = phase === 'validate' ? '検証中' : '登録中';
            document.getElementById('js-progress-text').textContent =
                phaseLabel + ' ' + formatNumber(processed) + ' / ' + formatNumber(total) + ' 件 (' + percentage + '%)';
        }

        function showSection(id) {
            ['js-upload-section', 'js-progress-section', 'js-result-section'].forEach(function (sectionId) {
                const section = document.getElementById(sectionId);
                if (section) {
                    section.style.display = sectionId === id ? '' : 'none';
                }
            });
        }

        function cleanupTableSection(section) {
            if (!section) {
                return;
            }
            const rows = section.querySelectorAll('tbody tr');
            section.style.display = rows.length ? '' : 'none';
        }

        function cleanupPendingSection() {
            cleanupTableSection(document.getElementById('js-pending-section'));
        }

        function setBusyState(isBusy) {
            document.body.classList.toggle('bc-csv-import-core--busy', isBusy);
        }

        function resetUploadState() {
            const startButton = document.getElementById('js-start-btn');
            const cancelButton = document.getElementById('js-cancel-btn');
            if (startButton) {
                startButton.disabled = false;
            }
            if (cancelButton) {
                cancelButton.disabled = false;
            }
            document.getElementById('js-progress-title').textContent = '処理中...';
            document.getElementById('js-progress-bar').style.width = '0%';
            setProgressPhase('import');
            setBusyState(false);
        }

        function showUploadError(message) {
            const element = document.getElementById('js-upload-error');
            element.textContent = message;
            element.style.display = '';
            element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function hideUploadError() {
            const element = document.getElementById('js-upload-error');
            element.textContent = '';
            element.style.display = 'none';
        }

        function showResult(result) {
            document.getElementById('js-res-total').textContent = formatNumber(result.total) + ' 件';
            document.getElementById('js-res-success').textContent = formatNumber(result.success_count) + ' 件';

            const duplicateSkip = Math.max(0, (result.skip_count || 0) - (result.error_count || 0));
            const duplicateRow = document.getElementById('js-dup-row');
            if (duplicateRow) {
                if (duplicateSkip > 0) {
                    document.getElementById('js-res-dup').textContent = formatNumber(duplicateSkip) + ' 件';
                    duplicateRow.style.display = '';
                } else {
                    duplicateRow.style.display = 'none';
                }
            }

            const errorCountRow = document.getElementById('js-error-count-row');
            if ((result.error_count || 0) > 0) {
                document.getElementById('js-res-error').textContent = formatNumber(result.error_count) + ' 件';
                errorCountRow.style.display = '';
            } else {
                errorCountRow.style.display = 'none';
            }

            const totalSeconds = (Date.now() - startTime) / 1000;
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = (totalSeconds % 60).toFixed(1);
            let timeText = '';
            if (hours > 0) {
                timeText += hours + ' 時間';
            }
            if (hours > 0 || minutes > 0) {
                timeText += minutes + ' 分';
            }
            timeText += seconds + ' 秒';
            document.getElementById('js-res-time').textContent = timeText;

            if (result.error_count > 0) {
                document.getElementById('js-error-list-area').style.display = '';
                document.getElementById('js-download-errors').href = adminBase + '/download_errors/' + currentToken;
            } else {
                document.getElementById('js-error-list-area').style.display = 'none';
            }
            showSection('js-result-section');
        }

        function showBatchErrors(errors) {
            const list = document.getElementById('js-error-list');
            const area = document.getElementById('js-error-list-area');
            errors.forEach(function (error) {
                const tr = document.createElement('tr');
                tr.className = 'bca-table-listup__tbody-tr';
                tr.innerHTML = '<td class="bca-table-listup__tbody-td">' + error.row + '</td>' +
                    '<td class="bca-table-listup__tbody-td">' + (error.label || error.field) + '</td>' +
                    '<td class="bca-table-listup__tbody-td">' + error.message + '</td>';
                list.appendChild(tr);
            });
            if (errors.length > 0) {
                area.style.display = '';
            }
        }

        function confirmReplaceImport(mode, importStrategy, targetCleared) {
            if (importStrategy !== 'replace' || targetCleared) {
                return true;
            }

            const modeMessage = mode === 'strict'
                ? '事前確認モードのため、検証エラーがあれば既存データは削除されません。'
                : 'スキップモードのため、登録開始時に既存データが削除されます。';

            return window.confirm(
                '全件入れ替えモードです。\n\n' +
                'インポート開始時に既存データを削除します。\n' +
                'この操作は元に戻せません。\n\n' +
                modeMessage + '\n\n' +
                '続行しますか？'
            );
        }

        async function deleteJob(token, row, status) {
            const message = status === 'failed'
                ? 'この失敗ジョブを削除します。エラーログとアップロード済みCSVも削除されます。よろしいですか？'
                : 'このジョブを削除します。アップロード済みCSVとエラーログも削除されます。よろしいですか？';

            if (!window.confirm(message)) {
                return;
            }

            await apiPost(adminBase + '/delete/' + token, {});
            row.remove();
            cleanupTableSection(row.closest('section'));
        }

        async function startImport(token, total, mode, importStrategy, resumeOffset, resumePhase) {
            currentToken = token;
            cancelled = false;
            startTime = Date.now();
            document.getElementById('js-cancel-btn').disabled = false;
            setBusyState(true);
            showSection('js-progress-section');
            document.getElementById('js-error-list').innerHTML = '';
            document.getElementById('js-error-list-area').style.display = 'none';

            let totalErrorCount = 0;

            try {
                if (mode === 'strict' && resumePhase === 'validate') {
                    let offset = resumeOffset || 0;
                    document.getElementById('js-progress-title').textContent = '検証中...';
                    setProgressPhase('validate');
                    while (offset < total && !cancelled) {
                        const response = await apiPost(adminBase + '/validate_batch', { token: token, offset: offset, limit: batchSize });
                        if (response.result) {
                            offset = response.result.processed;
                            totalErrorCount = response.result.error_count;
                            updateProgress(offset, total, 'validate');
                            if (response.result.batch_errors?.length) {
                                showBatchErrors(response.result.batch_errors);
                            }
                        } else {
                            break;
                        }
                    }
                    if (cancelled) {
                        await apiPost(adminBase + '/cancel/' + token, {});
                        showSection('js-upload-section');
                        resetUploadState();
                        return;
                    }
                    if (totalErrorCount > 0) {
                        showResult({ total: total, success_count: 0, skip_count: 0, error_count: totalErrorCount });
                        resetUploadState();
                        return;
                    }
                }

                let offset = resumePhase === 'import' ? (resumeOffset || 0) : 0;
                document.getElementById('js-progress-title').textContent = '登録中...';
                setProgressPhase('import');
                let lastResult = null;
                while (offset < total && !cancelled) {
                    const response = await apiPost(adminBase + '/process_batch', { token: token, offset: offset, limit: batchSize });
                    if (response.result) {
                        offset = response.result.processed;
                        lastResult = response.result;
                        updateProgress(offset, total, 'import');
                        if (response.result.batch_errors?.length) {
                            showBatchErrors(response.result.batch_errors);
                        }
                    } else {
                        break;
                    }
                }

                if (cancelled) {
                    await apiPost(adminBase + '/cancel/' + token, {});
                    showSection('js-upload-section');
                    resetUploadState();
                    return;
                }

                if (lastResult) {
                    showResult(lastResult);
                }
                resetUploadState();
            } catch (error) {
                resetUploadState();
                showSection('js-upload-section');
                showUploadError('処理中にエラーが発生しました: ' + (error.message || '不明なエラー'));
            }
        }

        const startButton = document.getElementById('js-start-btn');
        if (startButton) {
            startButton.addEventListener('click', async function () {
                const fileInput = document.getElementById('js-csv-file');
                if (blogContentSelect && !blogContentSelect.value) {
                    showUploadError('インポート先のブログを選択してください。');
                    return;
                }
                if (!fileInput.files.length) {
                    showUploadError('CSVファイルを選択してください。');
                    return;
                }

                const mode = (document.querySelector('input[name="mode"]:checked') || document.querySelector('input[name="mode"]'))?.value || 'strict';
                const encoding = document.getElementById('js-encoding').value;
                const importStrategy = document.getElementById('js-import-strategy').value;
                const duplicateMode = document.getElementById('js-duplicate-mode').value;

                if (!confirmReplaceImport(mode, importStrategy, false)) {
                    return;
                }

                const formData = new FormData();
                formData.append('csv_file', fileInput.files[0]);
                formData.append('mode', mode);
                formData.append('encoding', encoding);
                formData.append('import_strategy', importStrategy);
                formData.append('duplicate_mode', duplicateMode);
                if (blogContentSelect && blogContentSelect.value) {
                    formData.append('blog_content_id', blogContentSelect.value);
                }

                startButton.disabled = true;
                setBusyState(true);

                try {
                    const response = await fetch(adminBase + '/upload', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': csrfToken },
                        body: formData,
                    });
                    const data = await response.json();
                    if (!data.job?.token) {
                        showUploadError(data.message || 'アップロードに失敗しました。');
                        startButton.disabled = false;
                        setBusyState(false);
                        return;
                    }
                    hideUploadError();
                    await startImport(data.job.token, data.job.total, mode, importStrategy, 0, 'validate');
                } catch (error) {
                    setBusyState(false);
                    showUploadError('エラーが発生しました: ' + error.message);
                    startButton.disabled = false;
                }
            });
        }

        const cancelButton = document.getElementById('js-cancel-btn');
        if (cancelButton) {
            cancelButton.addEventListener('click', async function () {
                cancelled = true;
                cancelButton.disabled = true;
                document.getElementById('js-progress-title').textContent = 'キャンセル中...';
                setBusyState(true);

                if (!currentToken) {
                    resetUploadState();
                    showSection('js-upload-section');
                    return;
                }

                try {
                    await apiPost(adminBase + '/cancel/' + currentToken, {});
                } catch (error) {
                    document.getElementById('js-error-text').textContent = error.message || 'キャンセルに失敗しました。';
                }

                resetUploadState();
                showSection('js-upload-section');
            });
        }

        const restartButton = document.getElementById('js-restart-btn');
        if (restartButton) {
            restartButton.addEventListener('click', function () {
                window.location.reload();
            });
        }

        if (downloadPostsButton && blogContentSelect) {
            downloadPostsButton.addEventListener('click', function (event) {
                event.preventDefault();
                if (!blogContentSelect.value || blogContentSelect.value === '0') {
                    showUploadError('ダウンロード対象のブログを選択してください。');
                    return;
                }
                hideUploadError();
                window.location.href = adminBase + '/download_posts?blog_content_id=' + encodeURIComponent(blogContentSelect.value);
            });
        }

        document.querySelectorAll('.js-resume-btn').forEach(function (button) {
            button.addEventListener('click', async function () {
                const token = button.dataset.token;
                const total = parseInt(button.dataset.total || '0', 10);
                const processed = parseInt(button.dataset.processed || '0', 10);
                const phase = button.dataset.phase;
                const mode = button.dataset.mode;
                const importStrategy = button.dataset.importStrategy || 'append';
                const targetCleared = button.dataset.targetCleared === '1';
                if (!confirmReplaceImport(mode, importStrategy, targetCleared)) {
                    return;
                }
                button.closest('tr').remove();
                cleanupPendingSection();
                await startImport(token, total, mode, importStrategy, processed, phase);
            });
        });

        document.querySelectorAll('.js-delete-btn').forEach(function (button) {
            button.addEventListener('click', async function () {
                try {
                    await deleteJob(button.dataset.token, button.closest('tr'), button.dataset.status || 'pending');
                } catch (error) {
                    window.alert('ジョブ削除に失敗しました: ' + (error.message || 'エラーが発生しました。'));
                }
            });
        });

        cleanupPendingSection();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
