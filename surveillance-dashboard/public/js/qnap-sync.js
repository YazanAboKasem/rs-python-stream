/**
 * RoadShield Recording Sync Controller
 * Syncs recordings from Jetson to VPS server via HTTP upload.
 */
(function () {
    'use strict';

    let syncInterval = null;
    let currentSyncId = null;
    let syncState = 'stopped'; // stopped, syncing, paused, completed, error
    let filesList = null;

    /**
     * Open Sync Modal
     */
    window.openSyncModal = function () {
        const modal = document.getElementById('qnap-sync-modal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    };

    /**
     * Close Sync Modal
     */
    window.closeSyncModal = function () {
        const modal = document.getElementById('qnap-sync-modal');
        if (modal) {
            modal.classList.add('hidden');
        }
    };

    /**
     * Toggle Scope inputs (disable/enable Days or Cameras select)
     */
    window.toggleScopeInputs = function () {
        const scope = document.querySelector('input[name="sync-scope"]:checked')?.value;
        const daysInput = document.getElementById('sync-days');
        const camerasRow = document.getElementById('cameras-selection-row');

        if (scope === 'last_n_days') {
            daysInput.removeAttribute('disabled');
        } else {
            daysInput.setAttribute('disabled', 'true');
        }

        if (scope === 'cameras') {
            camerasRow.classList.remove('hidden');
        } else {
            camerasRow.classList.add('hidden');
        }
    };

    /**
     * Submit form & start Sync
     */
    window.submitSyncForm = function (e) {
        e.preventDefault();

        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const errLabel = document.getElementById('qnap-error-msg');
        errLabel.classList.add('hidden');

        const scope = document.querySelector('input[name="sync-scope"]:checked')?.value;
        const selectedCams = Array.from(document.querySelectorAll('input[name="sync-cameras"]:checked')).map(el => el.value);

        const payload = {
            scope: scope,
            cameras: scope === 'cameras' ? selectedCams : [],
            days: scope === 'last_n_days' ? parseInt(document.getElementById('sync-days').value, 10) : null,
            delete_after_upload: document.getElementById('delete-after-upload').checked,
            overwrite_existing: document.getElementById('overwrite-existing').checked,
        };

        fetch('/api/surveillance/sync/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf
            },
            body: JSON.stringify(payload)
        })
        .then(r => {
            if (!r.ok) return r.json().then(err => { throw new Error(err.error || 'Sync start failed') });
            return r.json();
        })
        .then(data => {
            if (data.success) {
                currentSyncId = data.request_id;
                closeSyncModal();
                showProgressPanel();
                startSyncPolling();
            }
        })
        .catch(err => {
            console.error('[Sync] Error starting sync:', err);
            errLabel.textContent = err.message;
            errLabel.classList.remove('hidden');
        });
    };

    /**
     * Show/Hide Progress Panel
     */
    function showProgressPanel() {
        const panel = document.getElementById('sv-sync-progress-panel');
        if (panel) {
            panel.classList.remove('hidden');
            document.getElementById('sync-completion-report').classList.add('hidden');
            document.getElementById('sync-progress-fill').style.width = '0%';
            document.getElementById('sync-progress-text').textContent = 'Initialising connection...';
            document.getElementById('sync-stat-uploaded').textContent = '0.0 GB / 0.0 GB';
            document.getElementById('sync-stat-speed').textContent = '0.0 Mbps';
            document.getElementById('sync-stat-eta').textContent = 'Calculating...';
            document.getElementById('sync-current-file').textContent = 'Connecting to server...';
            document.getElementById('sync-action-buttons').classList.remove('hidden');
            
            // Set Pause label correctly
            document.getElementById('sync-pause-btn').innerHTML = `<i class="bi bi-pause-fill"></i> Pause`;
            syncState = 'syncing';
            
            // Reset files list
            filesList = null;
            const scrollContainer = document.getElementById('sync-files-list-scroll');
            if (scrollContainer) {
                scrollContainer.innerHTML = `<div style="color: var(--text-muted); text-align: center;">Initializing file list...</div>`;
            }
        }
    }

    /**
     * Start Polling progress
     */
    function startSyncPolling() {
        stopSyncPolling();
        syncInterval = setInterval(pollSyncProgress, 1000);
    }

    function stopSyncPolling() {
        if (syncInterval) {
            clearInterval(syncInterval);
            syncInterval = null;
        }
    }

    /**
     * Poll sync progress from cache
     */
    function pollSyncProgress() {
        if (!currentSyncId) return;
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';

        fetch(`/api/surveillance/sync/progress/${currentSyncId}`, {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        })
        .then(r => r.json())
        .then(data => {
            // Handle validation errors on start
            if (data.start_ack && data.start_ack.status === 'error') {
                handleSyncError(data.start_ack.error);
                return;
            }

            // Capture files list
            if (data.start_ack && data.start_ack.files_list && !filesList) {
                filesList = data.start_ack.files_list;
            }

            // Handle progress updates
            if (data.progress) {
                updateProgressUI(data.progress);
            }

            // Handle completion/error
            if (data.complete) {
                handleSyncComplete(data.complete);
            }
        })
        .catch(err => console.error('[Sync] Progress poll error:', err));
    }

    /**
     * Update progress bars & details
     */
    function updateProgressUI(p) {
        const percent = p.percent !== undefined ? parseFloat(p.percent).toFixed(1) : '0.0';
        document.getElementById('sync-progress-fill').style.width = `${percent}%`;
        document.getElementById('sync-progress-text').textContent = `${p.files_uploaded}/${p.files_total} files (${percent}%)`;
        
        const uploadedGb = (p.bytes_uploaded / (1024 * 1024 * 1024)).toFixed(1);
        const totalGb = (p.bytes_total / (1024 * 1024 * 1024)).toFixed(1);
        document.getElementById('sync-stat-uploaded').textContent = `${uploadedGb} GB / ${totalGb} GB`;

        document.getElementById('sync-stat-speed').textContent = `${p.speed_mbps !== undefined ? parseFloat(p.speed_mbps).toFixed(1) : '0.0'} Mbps`;

        // Format ETA
        const eta = p.eta_seconds;
        let etaText = 'Calculating...';
        if (eta !== undefined && eta > 0) {
            const mins = Math.floor(eta / 60);
            const secs = eta % 60;
            etaText = mins > 0 ? `${mins}m ${secs}s remaining` : `${secs}s remaining`;
        }
        document.getElementById('sync-stat-eta').textContent = etaText;

        document.getElementById('sync-current-file').textContent = p.current_file || 'None';

        // Update scroll container with file queue status
        if (filesList && filesList.length > 0) {
            const scrollContainer = document.getElementById('sync-files-list-scroll');
            if (scrollContainer) {
                const currentFile = p.current_file;
                const currentIndex = filesList.indexOf(currentFile);
                
                // Get the list of failed file names
                const failedFileNames = (p.failed_files || []).map(ff => ff.file);
                
                scrollContainer.innerHTML = filesList.map((filename, idx) => {
                    let statusHtml = '';
                    let itemStyle = 'display:flex; justify-content:space-between; align-items:center; padding: 4px 8px; border-radius: 4px;';
                    
                    if (failedFileNames.includes(filename)) {
                        statusHtml = `<span style="color:var(--red); font-weight:bold;"><i class="bi bi-x-circle-fill"></i> Failed</span>`;
                        itemStyle += 'background: rgba(239, 83, 80, 0.1); border: 1px solid rgba(239, 83, 80, 0.2);';
                    } else if (filename === currentFile) {
                        statusHtml = `<span style="color:var(--accent); font-weight:bold;"><i class="bi bi-arrow-repeat" style="display:inline-block; animation:sv-spin 1s linear infinite;"></i> Syncing...</span>`;
                        itemStyle += 'background: rgba(255, 171, 64, 0.1); border: 1px solid rgba(255, 171, 64, 0.2);';
                    } else if (currentIndex !== -1 && idx < currentIndex) {
                        statusHtml = `<span style="color:var(--green); font-weight:bold;"><i class="bi bi-check-circle-fill"></i> Synced</span>`;
                        itemStyle += 'background: rgba(76, 175, 80, 0.15); border: 1px solid rgba(76, 175, 80, 0.2);';
                    } else {
                        statusHtml = `<span style="color:var(--text-muted);"><i class="bi bi-hourglass-split"></i> Pending</span>`;
                        itemStyle += 'opacity: 0.6;';
                    }
                    
                    return `<div style="${itemStyle}">
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;" title="${filename}">${filename}</span>
                        ${statusHtml}
                    </div>`;
                }).join('');
            }
        }
    }

    /**
     * Pause upload
     */
    window.pauseSync = function () {
        if (!currentSyncId) return;
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const action = syncState === 'paused' ? 'resume' : 'pause';

        fetch(`/api/surveillance/sync/${action}/${currentSyncId}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const btn = document.getElementById('sync-pause-btn');
                if (action === 'pause') {
                    syncState = 'paused';
                    btn.innerHTML = `<i class="bi bi-play-fill"></i> Resume`;
                    document.getElementById('sync-current-file').textContent = 'Sync paused.';
                } else {
                    syncState = 'syncing';
                    btn.innerHTML = `<i class="bi bi-pause-fill"></i> Pause`;
                    document.getElementById('sync-current-file').textContent = 'Resuming...';
                }
            }
        })
        .catch(err => console.error('[Sync] Error pausing/resuming:', err));
    };

    /**
     * Cancel upload
     */
    window.cancelSync = function () {
        if (!currentSyncId || !confirm('Are you sure you want to cancel the recording sync?')) return;
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        fetch(`/api/surveillance/sync/cancel/${currentSyncId}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                handleSyncCancelled();
            }
        })
        .catch(err => console.error('[Sync] Error cancelling sync:', err));
    };

    /**
     * Handle Sync Complete status report
     */
    function handleSyncComplete(c) {
        stopSyncPolling();
        syncState = 'completed';

        document.getElementById('sync-progress-fill').style.width = '100%';
        document.getElementById('sync-progress-fill').style.background = 'var(--green)';
        document.getElementById('sync-action-buttons').classList.add('hidden');
        document.getElementById('sync-current-file').textContent = 'Sync completed successfully.';

        const report = document.getElementById('sync-completion-report');
        const title = document.getElementById('sync-report-title');
        const stats = document.getElementById('sync-report-stats');
        const failedList = document.getElementById('sync-failed-files-list');
        const failedUl = document.getElementById('sync-failed-files-ul');

        report.classList.remove('hidden');
        title.textContent = 'Sync Completion Report';
        title.className = 'sv-report-title success';

        stats.innerHTML = `
            <div class="report-stat-row"><span>Status:</span> <span class="val green">Completed</span></div>
            <div class="report-stat-row"><span>Total Files:</span> <span class="val">${c.files_uploaded} uploaded</span></div>
            <div class="report-stat-row"><span>Failed Files:</span> <span class="val">${c.files_failed || 0} failed</span></div>
            <div class="report-stat-row"><span>Uploaded Size:</span> <span class="val">${c.total_uploaded_gb || 0} GB</span></div>
            <div class="report-stat-row"><span>Time Elapsed:</span> <span class="val">${c.duration_minutes || 0} mins</span></div>
            <div class="report-stat-row"><span>Local Space Cleared:</span> <span class="val">${c.local_files_deleted || 0} files deleted</span></div>`;

        if (c.failed_files && c.failed_files.length > 0) {
            failedList.classList.remove('hidden');
            failedUl.innerHTML = c.failed_files.map(f => `<li><span class="file">${f.file}</span>: <span class="reason">${f.error}</span></li>`).join('');
        } else {
            failedList.classList.add('hidden');
        }

        // Update files list one last time on complete
        if (filesList && filesList.length > 0) {
            const scrollContainer = document.getElementById('sync-files-list-scroll');
            if (scrollContainer) {
                const failedFileNames = (c.failed_files || []).map(ff => ff.file);
                
                scrollContainer.innerHTML = filesList.map((filename) => {
                    let statusHtml = '';
                    let itemStyle = 'display:flex; justify-content:space-between; align-items:center; padding: 4px 8px; border-radius: 4px;';
                    
                    if (failedFileNames.includes(filename)) {
                        statusHtml = `<span style="color:var(--red); font-weight:bold;"><i class="bi bi-x-circle-fill"></i> Failed</span>`;
                        itemStyle += 'background: rgba(239, 83, 80, 0.1); border: 1px solid rgba(239, 83, 80, 0.2);';
                    } else {
                        statusHtml = `<span style="color:var(--green); font-weight:bold;"><i class="bi bi-check-circle-fill"></i> Synced</span>`;
                        itemStyle += 'background: rgba(76, 175, 80, 0.15); border: 1px solid rgba(76, 175, 80, 0.2);';
                    }
                    
                    return `<div style="${itemStyle}">
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;" title="${filename}">${filename}</span>
                        ${statusHtml}
                    </div>`;
                }).join('');
            }
        }
    }

    /**
     * Handle Sync Error status report
     */
    function handleSyncError(errMsg) {
        stopSyncPolling();
        syncState = 'error';

        document.getElementById('sync-progress-fill').style.background = 'var(--red)';
        document.getElementById('sync-action-buttons').classList.add('hidden');
        document.getElementById('sync-current-file').textContent = 'Sync terminated with errors.';

        const report = document.getElementById('sync-completion-report');
        const title = document.getElementById('sync-report-title');
        const stats = document.getElementById('sync-report-stats');
        const failedList = document.getElementById('sync-failed-files-list');

        report.classList.remove('hidden');
        title.textContent = 'Sync Connection Failed';
        title.className = 'sv-report-title error';

        stats.innerHTML = `
            <div class="report-stat-row"><span>Status:</span> <span class="val red">Failed</span></div>
            <div class="report-stat-row"><span>Error Detail:</span> <span class="val error-msg">${errMsg}</span></div>`;

        failedList.classList.add('hidden');
    }

    /**
     * Handle Sync Cancelled status report
     */
    function handleSyncCancelled() {
        stopSyncPolling();
        syncState = 'stopped';

        document.getElementById('sync-progress-fill').style.background = 'var(--amber)';
        document.getElementById('sync-action-buttons').classList.add('hidden');
        document.getElementById('sync-current-file').textContent = 'Sync cancelled by user.';

        const report = document.getElementById('sync-completion-report');
        const title = document.getElementById('sync-report-title');
        const stats = document.getElementById('sync-report-stats');
        const failedList = document.getElementById('sync-failed-files-list');

        report.classList.remove('hidden');
        title.textContent = 'Sync Cancelled';
        title.className = 'sv-report-title warn';

        stats.innerHTML = `
            <div class="report-stat-row"><span>Status:</span> <span class="val warn">Cancelled</span></div>
            <div class="report-stat-row"><span>Notice:</span> <span class="val">The transfer process was halted. Some files may have uploaded.</span></div>`;

        failedList.classList.add('hidden');
    }

    /**
     * Dismiss sync progress panel
     */
    window.dismissSyncReport = function () {
        const panel = document.getElementById('sv-sync-progress-panel');
        if (panel) {
            panel.classList.add('hidden');
        }
        currentSyncId = null;
        syncState = 'stopped';
    };

})();
