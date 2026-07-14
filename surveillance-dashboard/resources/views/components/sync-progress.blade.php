<div id="sv-sync-progress-panel" class="sv-sync-panel hidden">
    <div class="sv-panel-header">
        <div class="sv-panel-header-title">
            <i class="bi bi-cloud-arrow-up-fill pulse" style="color:var(--green)"></i>
            <span>Syncing Recordings to Server</span>
        </div>
        <div class="sv-panel-header-actions" id="sync-action-buttons">
            <button class="sv-btn sv-btn-secondary" id="sync-pause-btn" onclick="pauseSync()">
                <i class="bi bi-pause-fill"></i> Pause
            </button>
            <button class="sv-btn sv-btn-danger" id="sync-cancel-btn" onclick="cancelSync()">
                <i class="bi bi-x-circle-fill"></i> Cancel Sync
            </button>
        </div>
    </div>
    
    <div class="sv-panel-body-sub">
        <!-- Progress Bar Row -->
        <div class="sv-progress-row">
            <div class="sv-progress-bar-container">
                <div class="sv-progress-bar-fill" id="sync-progress-fill" style="width: 0%"></div>
            </div>
            <div class="sv-progress-text" id="sync-progress-text">0/0 files (0%)</div>
        </div>

        <!-- Stats Grid -->
        <div class="sv-sync-stats-grid">
            <div class="sv-sync-stat-box">
                <span class="sv-sync-stat-label">Uploaded</span>
                <span class="sv-sync-stat-val" id="sync-stat-uploaded">0.0 GB / 0.0 GB</span>
            </div>
            <div class="sv-sync-stat-box">
                <span class="sv-sync-stat-label">Speed</span>
                <span class="sv-sync-stat-val" id="sync-stat-speed">0.0 Mbps</span>
            </div>
            <div class="sv-sync-stat-box">
                <span class="sv-sync-stat-label">ETA</span>
                <span class="sv-sync-stat-val" id="sync-stat-eta">Calculating...</span>
            </div>
        </div>

        <!-- Current File -->
        <div class="sv-current-file-box">
            <span class="sv-current-file-label">Current File:</span>
            <span class="sv-current-file-val" id="sync-current-file">None</span>
        </div>

        <!-- Error/Complete Report -->
        <div id="sync-completion-report" class="sv-report-box hidden">
            <h4 class="sv-report-title" id="sync-report-title">Sync Complete</h4>
            <div class="sv-report-stats" id="sync-report-stats">
                <!-- Will be dynamically populated -->
            </div>
            <div id="sync-failed-files-list" class="sv-failed-files-container hidden">
                <h5>Failed Files:</h5>
                <ul id="sync-failed-files-ul">
                    <!-- Populated dynamically -->
                </ul>
            </div>
            <div style="margin-top: 12px; text-align: right">
                <button class="sv-btn sv-btn-secondary" onclick="dismissSyncReport()">Dismiss</button>
            </div>
        </div>
    </div>
</div>
