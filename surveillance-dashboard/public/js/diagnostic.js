/**
 * RoadShield Diagnostics & Test Mode Controller
 */
(function () {
    'use strict';

    let diagnosticInterval = null;
    let diagnosticTimeout = null;
    let currentRequestId = null;
    let logLines = [];
    let diagnosticStartTime = null;
    let receivedSections = { cameras: false, streams: false, tunnel: false, logs: false };
    let lastKnownJetsonOnline = null;

    const DIAGNOSTIC_TIMEOUT_MS = 20000; // 20 seconds before showing timeout error
    const POLL_INTERVAL_MS = 1500;

    // Boot & Status Loop
    window.addEventListener('DOMContentLoaded', function () {
        updateJetsonStatus();
        setInterval(updateJetsonStatus, 10000); // every 10s
    });

    /**
     * Poll Jetson WebSocket Status and update topbar indicator
     */
    window.updateJetsonStatus = function() {
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        
        fetch('/api/surveillance/jetson/status', {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        })
        .then(r => r.json())
        .then(data => {
            const pill = document.getElementById('jetson-status-pill');
            if (pill) {
                if (data.online) {
                    pill.classList.remove('offline');
                    pill.classList.add('online');
                    pill.innerHTML = `<span class="sv-status-dot"></span> JETSON ONLINE (v${data.version})`;
                } else {
                    // Only update if it is not currently showing "REBOOTING..." set by click handler
                    if (!pill.innerHTML.includes('REBOOTING')) {
                        pill.classList.remove('online');
                        pill.classList.add('offline');
                        pill.innerHTML = `<span class="sv-status-dot"></span> JETSON OFFLINE`;
                    }
                }
            }

            const rebootBtn = document.getElementById('reboot-jetson-btn');
            if (rebootBtn) {
                // If it is currently rebooting, keep it disabled
                if (rebootBtn.innerHTML.includes('Rebooting')) {
                    rebootBtn.setAttribute('disabled', 'true');
                } else {
                    if (data.online) {
                        rebootBtn.removeAttribute('disabled');
                    } else {
                        rebootBtn.setAttribute('disabled', 'true');
                    }
                }
            }
        })
        .catch(err => console.error('[Status] Error updating Jetson status:', err));
    };

    /**
     * Reboot Jetson via Laravel WS API
     */
    window.rebootJetson = function() {
        if (!confirm('Are you sure you want to reboot the Jetson system? This will interrupt the live streams for a couple of minutes.')) {
            return;
        }

        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const btn = document.getElementById('reboot-jetson-btn');

        if (btn) {
            btn.setAttribute('disabled', 'true');
            btn.innerHTML = `<span class="sv-spinner-sm" style="border-top-color:#ff5252"></span> Rebooting...`;
        }

        fetch('/api/surveillance/jetson/reboot', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf
            }
        })
        .then(r => {
            if (!r.ok) return r.json().then(err => { throw new Error(err.error || 'Reboot request failed') });
            return r.json();
        })
        .then(data => {
            alert('Reboot command successfully sent to the Jetson! It will restart now.');
            // Update the Jetson status immediately to offline/rebooting
            const pill = document.getElementById('jetson-status-pill');
            if (pill) {
                pill.classList.remove('online');
                pill.classList.add('offline');
                pill.innerHTML = `<span class="sv-status-dot"></span> JETSON REBOOTING...`;
            }
            if (btn) {
                btn.innerHTML = `<i class="bi bi-power"></i> Restart Jetson`;
            }
        })
        .catch(err => {
            alert('Error: ' + err.message);
            if (btn) {
                btn.removeAttribute('disabled');
                btn.innerHTML = `<i class="bi bi-power"></i> Restart Jetson`;
            }
        });
    };

    /**
     * Toggle Test Mode Panel
     */
    window.toggleTestMode = function () {
        const panel = document.getElementById('sv-diagnostic-panel');
        const btn = document.getElementById('test-mode-toggle-btn');
        if (!panel) return;

        const isHidden = panel.classList.contains('hidden');
        if (isHidden) {
            panel.classList.remove('hidden');
            btn.classList.add('active');
            btn.innerHTML = `<i class="bi bi-cpu-fill"></i> Test Mode Active`;
            startDiagnostics();
        } else {
            exitTestMode();
        }
    };

    /**
     * Exit Test Mode
     */
    window.exitTestMode = function () {
        const panel = document.getElementById('sv-diagnostic-panel');
        const btn = document.getElementById('test-mode-toggle-btn');
        if (panel) panel.classList.add('hidden');
        if (btn) {
            btn.classList.remove('active');
            btn.innerHTML = `<i class="bi bi-cpu-fill"></i> Test Mode`;
        }
        stopDiagnosticPolling();
    };

    /**
     * Start Diagnostic Checks
     */
    window.startDiagnostics = function () {
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

        stopDiagnosticPolling();
        resetDiagnosticUI();

        fetch('/api/surveillance/diagnostic/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN': csrf
            }
        })
        .then(r => {
            if (!r.ok) return r.json().then(err => { throw new Error(err.error || 'Diagnostic trigger failed') });
            return r.json();
        })
        .then(data => {
            if (data.success) {
                currentRequestId = data.request_id;
                diagnosticStartTime = Date.now();
                receivedSections = { cameras: false, streams: false, tunnel: false, logs: false };
                // Start polling results
                diagnosticInterval = setInterval(pollDiagnosticStatus, POLL_INTERVAL_MS);
            } else {
                showDiagnosticError(data.error || 'Failed to trigger diagnostics.');
            }
        })
        .catch(err => {
            console.error('[Diagnostics] Failed to start:', err);
            showDiagnosticError(err.message);
        });
    };

    /**
     * Stop Polling Diagnostic Results
     */
    function stopDiagnosticPolling() {
        if (diagnosticInterval) {
            clearInterval(diagnosticInterval);
            diagnosticInterval = null;
        }
        if (diagnosticTimeout) {
            clearTimeout(diagnosticTimeout);
            diagnosticTimeout = null;
        }
        diagnosticStartTime = null;
    }

    /**
     * Refresh Diagnostics
     */
    window.refreshDiagnostics = function () {
        startDiagnostics();
    };

    /**
     * Reset UI panels to loading state
     */
    function resetDiagnosticUI() {
        logLines = [];
        document.getElementById('diag-cameras-list').innerHTML = `
            <div class="sv-diagnostic-loading">
                <div class="sv-spinner-sm"></div> Running camera checks...
            </div>`;
        document.getElementById('diag-streams-list').innerHTML = `
            <tr>
                <td colspan="3" class="sv-td-loading">
                    <div class="sv-spinner-sm"></div> Checking streams...
                </td>
            </tr>`;
        document.getElementById('diag-tunnel-info').innerHTML = `
            <div class="sv-diagnostic-loading">
                <div class="sv-spinner-sm"></div> Probing tunnel...
            </div>`;
        document.getElementById('diag-log-list').innerHTML = `
            <div class="sv-log-line system">Waiting for logs from Jetson...</div>`;
        document.getElementById('diag-log-count').textContent = 'Showing 0 lines';
    }

    /**
     * Show timeout error for a specific section
     */
    function showSectionTimeout(elementId, sectionName) {
        const el = document.getElementById(elementId);
        if (!el) return;

        let reason;
        if (lastKnownJetsonOnline === false) {
            reason = 'Jetson is <strong>OFFLINE</strong>. Make sure connect-to-server.sh is running on the Jetson device.';
        } else {
            reason = `Jetson did not respond within ${DIAGNOSTIC_TIMEOUT_MS / 1000}s. The WebSocket server (php artisan websocket:serve) may not be running, or there is a connectivity issue.`;
        }

        if (elementId === 'diag-streams-list') {
            el.innerHTML = `
                <tr>
                    <td colspan="3" class="sv-td-loading">
                        <div class="sv-tunnel-status-alert error" style="margin:0">
                            <div class="sv-alert-header">
                                <i class="bi bi-exclamation-triangle-fill"></i> ${sectionName} — No Response
                            </div>
                            <p class="sv-mono" style="font-size:0.8rem;margin-top:4px">${reason}</p>
                        </div>
                    </td>
                </tr>`;
        } else if (elementId === 'diag-log-list') {
            el.innerHTML = `
                <div class="sv-log-line error">
                    <span class="sv-log-level">TIMEOUT</span>
                    <span class="sv-log-msg">${reason}</span>
                </div>`;
        } else {
            el.innerHTML = `
                <div class="sv-tunnel-status-alert error">
                    <div class="sv-alert-header">
                        <i class="bi bi-exclamation-triangle-fill"></i> ${sectionName} — No Response
                    </div>
                    <p class="sv-mono" style="font-size:0.8rem;margin-top:4px">${reason}</p>
                </div>`;
        }
    }

    /**
     * Check if all sections have timed out and stop polling
     */
    function checkAllTimedOut() {
        if (!diagnosticStartTime) return;
        const elapsed = Date.now() - diagnosticStartTime;
        if (elapsed < DIAGNOSTIC_TIMEOUT_MS) return;

        // Show timeout for each section that hasn't received data
        if (!receivedSections.cameras) {
            showSectionTimeout('diag-cameras-list', 'Camera Check');
        }
        if (!receivedSections.streams) {
            showSectionTimeout('diag-streams-list', 'Stream Check');
        }
        if (!receivedSections.tunnel) {
            showSectionTimeout('diag-tunnel-info', 'Tunnel Check');
        }
        if (!receivedSections.logs) {
            showSectionTimeout('diag-log-list', 'Log Retrieval');
        }

        // If no sections have received data at all, stop polling completely
        const anyReceived = Object.values(receivedSections).some(v => v);
        if (!anyReceived) {
            stopDiagnosticPolling();
            console.warn('[Diagnostics] Timeout: No data received from Jetson. Stopped polling.');
        } else {
            // Some data received — check if ALL timed-out sections are resolved
            const allResolved = Object.values(receivedSections).every(v => v);
            if (allResolved) {
                stopDiagnosticPolling();
                console.info('[Diagnostics] All sections received. Stopped polling.');
            }
        }
    }

    /**
     * Poll Status from Laravel Cache
     */
    function pollDiagnosticStatus() {
        if (!currentRequestId) return;
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';

        fetch(`/api/surveillance/diagnostic/status/${currentRequestId}`, {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        })
        .then(r => r.json())
        .then(data => {
            // Track Jetson online status for better error messages
            if (data.jetson_online !== undefined) {
                lastKnownJetsonOnline = data.jetson_online;
            }

            if (data.cameras) {
                receivedSections.cameras = true;
                renderCameras(data.cameras.cameras);
            }
            if (data.streams) {
                receivedSections.streams = true;
                renderStreams(data.streams.streams);
            }
            if (data.tunnel) {
                receivedSections.tunnel = true;
                renderTunnel(data.tunnel);
            }
            if (data.logs) {
                receivedSections.logs = true;
                renderLogs(data.logs.lines);
            }

            // Check for timeout on sections that haven't responded
            checkAllTimedOut();

            // If all sections received, stop polling
            const allReceived = Object.values(receivedSections).every(v => v);
            if (allReceived) {
                stopDiagnosticPolling();
                console.info('[Diagnostics] All sections received. Polling stopped.');
            }
        })
        .catch(err => {
            console.error('[Diagnostics] Poll error:', err);
            // Still check for timeout even on fetch error
            checkAllTimedOut();
        });
    }

    /**
     * Render Camera Status Cards
     */
    function renderCameras(cameras) {
        const container = document.getElementById('diag-cameras-list');
        if (!container || !cameras) return;

        let html = '';
        Object.keys(cameras).forEach(id => {
            const cam = cameras[id];
            const statusClass = cam.reachable ? (cam.rtsp_ok ? 'ok' : 'degraded') : 'offline';
            const statusText = cam.reachable ? (cam.rtsp_ok ? 'Connected' : 'RTSP Failure') : 'Offline';
            const latency = cam.latency_ms !== undefined ? `${cam.latency_ms}ms` : '—';
            
            html += `
                <div class="sv-diag-camera-card ${statusClass}">
                    <div class="sv-diag-cam-status-header">
                        <span class="sv-diag-cam-name">${id}</span>
                        <span class="sv-diag-status-badge">${statusText}</span>
                    </div>
                    <div class="sv-diag-cam-details">
                        <div><span class="lbl">IP:</span> ${cam.ip || 'Unknown'}</div>
                        <div><span class="lbl">Latency:</span> ${latency}</div>
                        <div><span class="lbl">Model:</span> ${cam.model || 'Unknown'}</div>
                        ${cam.fallback_active ? '<div class="sv-fallback-active-tag"><i class="bi bi-exclamation-triangle-fill"></i> Fallback Active</div>' : ''}
                    </div>
                </div>`;
        });

        container.innerHTML = html || '<div class="sv-text-muted">No cameras reported.</div>';
    }

    /**
     * Render Stream Health Table
     */
    function renderStreams(streams) {
        const container = document.getElementById('diag-streams-list');
        if (!container || !streams) return;

        let html = '';
        Object.keys(streams).forEach(path => {
            const stream = streams[path];
            const activeClass = stream.active ? 'active' : 'inactive';
            const activeText = stream.active ? 'Active' : 'Inactive';
            const readers = stream.readers !== undefined ? stream.readers : 0;
            const detailText = stream.transcoding ? `Transcoding (${stream.transcoding})` : 
                               (stream.source ? `Source: ${stream.source}` : 'Waiting for clients');

            html += `
                <tr>
                    <td class="sv-mono">${path}</td>
                    <td><span class="sv-stream-badge ${activeClass}">${activeText}</span></td>
                    <td class="sv-text-secondary">${detailText} ${readers ? `(${readers} readers)` : ''}</td>
                </tr>`;
        });

        container.innerHTML = html || '<tr><td colspan="3" class="sv-text-muted">No stream path info.</td></tr>';
    }

    /**
     * Render Tunnel Info Card
     */
    function renderTunnel(tunnel) {
        const container = document.getElementById('diag-tunnel-info');
        if (!container) return;

        if (tunnel.error) {
            container.innerHTML = `
                <div class="sv-tunnel-status-alert error">
                    <div class="sv-alert-header">
                        <i class="bi bi-x-circle-fill"></i> Tunnel Error
                    </div>
                    <p class="sv-mono">${tunnel.error}</p>
                </div>`;
            return;
        }

        const isRunning = tunnel.tunnel_running;
        const statusClass = isRunning ? (tunnel.tunnel_accessible ? 'running' : 'degraded') : 'stopped';
        const statusText = isRunning ? (tunnel.tunnel_accessible ? 'Running & Accessible' : 'Process running, Inaccessible') : 'Stopped';

        container.innerHTML = `
            <div class="sv-tunnel-detail-card ${statusClass}">
                <div class="sv-tunnel-detail-row">
                    <span class="lbl">Tunnel Status</span>
                    <span class="val bold">${statusText}</span>
                </div>
                <div class="sv-tunnel-detail-row">
                    <span class="lbl">Tunnel URL</span>
                    <span class="val sv-mono">${tunnel.tunnel_url ? `<a href="${tunnel.tunnel_url}" target="_blank">${tunnel.tunnel_url}</a>` : '—'}</span>
                </div>
                <div class="sv-tunnel-detail-row">
                    <span class="lbl">PID</span>
                    <span class="val sv-mono">${tunnel.tunnel_pid || '—'}</span>
                </div>
                <div class="sv-tunnel-detail-row">
                    <span class="lbl">Latency</span>
                    <span class="val">${tunnel.tunnel_latency_ms ? `${tunnel.tunnel_latency_ms}ms` : '—'}</span>
                </div>
            </div>`;
    }

    /**
     * Render System Log Viewer lines
     */
    function renderLogs(lines) {
        const container = document.getElementById('diag-log-list');
        if (!container || !lines) return;

        // Keep local cache of lines
        logLines = lines;
        filterLogs();
    }

    /**
     * Filter logs based on search input
     */
    window.filterLogs = function () {
        const filterVal = document.getElementById('diag-log-filter')?.value.toLowerCase() || '';
        const container = document.getElementById('diag-log-list');
        const countLabel = document.getElementById('diag-log-count');
        if (!container) return;

        let visibleCount = 0;
        let html = '';

        logLines.forEach(line => {
            const msg = line.message || '';
            const lvl = (line.level || 'INFO').toUpperCase();
            const ts = line.timestamp || '';

            if (filterVal && !msg.toLowerCase().includes(filterVal) && !lvl.toLowerCase().includes(filterVal)) {
                return;
            }

            visibleCount++;
            let lvlClass = 'info';
            if (lvl.includes('WARN')) lvlClass = 'warn';
            if (lvl.includes('ERROR') || lvl.includes('CRIT') || lvl.includes('FATAL')) lvlClass = 'error';

            html += `
                <div class="sv-log-line ${lvlClass}">
                    <span class="sv-log-timestamp">${ts}</span>
                    <span class="sv-log-level">${lvl}</span>
                    <span class="sv-log-msg">${escapeHtml(msg)}</span>
                </div>`;
        });

        container.innerHTML = html || '<div class="sv-log-line system">No matching log lines found.</div>';
        if (countLabel) countLabel.textContent = `Showing ${visibleCount} of ${logLines.length} lines`;

        // Auto-scroll to bottom of log container
        const wrapper = document.getElementById('diag-log-container');
        if (wrapper) {
            wrapper.scrollTop = wrapper.scrollHeight;
        }
    };

    /**
     * Request more log lines
     */
    window.requestMoreLogs = function () {
        // Implement requesting more lines from Jetson if needed, or simply refresh
        updateJetsonStatus();
        startDiagnostics();
    };

    /**
     * Show Error Alert in Diagnostics Panel
     */
    function showDiagnosticError(msg) {
        document.getElementById('diag-cameras-list').innerHTML = `
            <div class="sv-tunnel-status-alert error">
                <i class="bi bi-x-circle-fill"></i> ${msg}
            </div>`;
        document.getElementById('diag-streams-list').innerHTML = `
            <tr>
                <td colspan="3">
                    <div class="sv-tunnel-status-alert error" style="margin:0">
                        <i class="bi bi-x-circle-fill"></i> ${msg}
                    </div>
                </td>
            </tr>`;
        document.getElementById('diag-tunnel-info').innerHTML = `
            <div class="sv-tunnel-status-alert error">
                <i class="bi bi-x-circle-fill"></i> ${msg}
            </div>`;
        document.getElementById('diag-log-list').innerHTML = `
            <div class="sv-log-line error">
                <span class="sv-log-level">ERROR</span>
                <span class="sv-log-msg">${msg}</span>
            </div>`;
    }

    /**
     * Escape HTML helper
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})();
