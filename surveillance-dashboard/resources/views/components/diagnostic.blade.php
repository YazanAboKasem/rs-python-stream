<div id="sv-diagnostic-panel" class="sv-diagnostic-panel hidden">
    <div class="sv-panel-header">
        <div class="sv-panel-header-title">
            <i class="bi bi-cpu-fill panel-header-icon"></i>
            <span>Test Mode — System Diagnostics</span>
        </div>
        <div class="sv-panel-header-actions">
            <button class="sv-btn sv-btn-secondary" onclick="refreshDiagnostics()">
                <i class="bi bi-arrow-clockwise"></i> Refresh All
            </button>
            <button class="sv-btn sv-btn-danger" onclick="exitTestMode()">
                <i class="bi bi-x-circle"></i> Exit Test Mode
            </button>
        </div>
    </div>

    <div class="sv-panel-grid">
        <!-- Cameras Status -->
        <div class="sv-panel-card" id="diag-card-cameras">
            <div class="sv-card-header-sub">
                <i class="bi bi-camera-video-fill"></i>
                Cameras
            </div>
            <div class="sv-card-body-sub">
                <div class="sv-camera-status-grid" id="diag-cameras-list">
                    <div class="sv-diagnostic-loading">
                        <div class="sv-spinner-sm"></div> Running camera checks...
                    </div>
                </div>
            </div>
        </div>

        <!-- Streams Health -->
        <div class="sv-panel-card" id="diag-card-streams">
            <div class="sv-card-header-sub">
                <i class="bi bi-activity"></i>
                Streams Health
            </div>
            <div class="sv-card-body-sub">
                <div class="sv-table-responsive">
                    <table class="sv-table">
                        <thead>
                            <tr>
                                <th>Stream Path</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="diag-streams-list">
                            <tr>
                                <td colspan="3" class="sv-td-loading">
                                    <div class="sv-spinner-sm"></div> Checking streams...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tunnel Status -->
        <div class="sv-panel-card" id="diag-card-tunnel">
            <div class="sv-card-header-sub">
                <i class="bi bi-cloud-arrow-up-fill"></i>
                Cloudflare Tunnel
            </div>
            <div class="sv-card-body-sub" id="diag-tunnel-info">
                <div class="sv-diagnostic-loading">
                    <div class="sv-spinner-sm"></div> Probing tunnel...
                </div>
            </div>
        </div>

        <!-- Log Viewer -->
        <div class="sv-panel-card full-width" id="diag-card-logs">
            <div class="sv-card-header-sub align-center">
                <div style="display:flex;align-items:center;gap:8px">
                    <i class="bi bi-file-earmark-text-fill"></i>
                    System Log Viewer
                </div>
                <div class="sv-log-controls">
                    <input type="text" id="diag-log-filter" class="sv-input-sm" placeholder="Filter logs..." oninput="filterLogs()">
                    <button class="sv-btn-sm sv-btn-secondary" onclick="requestMoreLogs()">
                        <i class="bi bi-plus-circle"></i> Load More Lines
                    </button>
                </div>
            </div>
            <div class="sv-card-body-sub">
                <div class="sv-log-container" id="diag-log-container">
                    <div class="sv-log-inner" id="diag-log-list">
                        <div class="sv-log-line system">Waiting for logs from Jetson...</div>
                    </div>
                </div>
                <div class="sv-log-footer">
                    <span id="diag-log-count" class="sv-text-muted">Showing 0 lines</span>
                </div>
            </div>
        </div>
    </div>
</div>
