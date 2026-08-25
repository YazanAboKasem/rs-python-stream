@extends('layouts.surveillance')

@section('title', $device['name'] . ' — Settings')
@section('page-title', $device['name'])

@section('content')

    {{-- Breadcrumb --}}
    <div class="sv-breadcrumb">
        <a href="{{ route('surveillance.devices') }}"><i class="bi bi-cpu-fill"></i> Devices</a>
        <i class="bi bi-chevron-right"></i>
        <span>{{ $device['name'] }}</span>
    </div>

    {{-- Device Info Card --}}
    <div class="sv-settings-section">
        <div class="sv-settings-card">
            <div class="sv-settings-card-header">
                <div class="sv-settings-card-title">
                    <i class="bi bi-cpu-fill"></i>
                    Device Information
                </div>
                <div class="sv-device-status-pill {{ $device['is_online'] ? 'online' : 'offline' }}">
                    <span class="sv-status-dot"></span>
                    {{ $device['is_online'] ? 'ONLINE' : 'OFFLINE' }}
                </div>
            </div>
            <div class="sv-settings-card-body">
                <div class="sv-settings-info-grid">
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">Device ID</span>
                        <span class="sv-settings-info-value mono">{{ $device['id'] }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">Name</span>
                        <span class="sv-settings-info-value">{{ $device['name'] }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">Location</span>
                        <span class="sv-settings-info-value">{{ $device['location'] ?? '—' }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">Host</span>
                        <span class="sv-settings-info-value mono">{{ $device['host'] }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">HLS Port</span>
                        <span class="sv-settings-info-value mono">{{ $device['hls_port'] }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">WebRTC Port</span>
                        <span class="sv-settings-info-value mono">{{ $device['webrtc_port'] }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">Cameras</span>
                        <span class="sv-settings-info-value sv-text-green">{{ count($device['cameras']) }}</span>
                    </div>
                    <div class="sv-settings-info-item">
                        <span class="sv-settings-info-label">HLS Base</span>
                        <span class="sv-settings-info-value mono" style="font-size:10px">{{ $device['hls_base'] ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="sv-settings-section">
        <div class="sv-settings-card">
            <div class="sv-settings-card-header">
                <div class="sv-settings-card-title">
                    <i class="bi bi-lightning-fill"></i>
                    Actions
                </div>
            </div>
            <div class="sv-settings-card-body">
                <div class="sv-settings-actions">
                    <a class="sv-btn sv-btn-secondary" id="sync-recordings-btn" href="{{ route('surveillance.device-sync', $device['id']) }}" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none">
                        <i class="bi bi-cloud-arrow-up-fill"></i> Sync Recordings
                    </a>
                    <button class="sv-btn sv-btn-secondary" id="test-mode-toggle-btn" onclick="toggleTestMode()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-cpu-fill"></i> Test Mode
                    </button>
                    <button class="sv-btn sv-btn-secondary" id="power-logs-btn" onclick="openPowerLogsModal()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-clock-history"></i> Power Logs
                    </button>
                    <button class="sv-btn sv-btn-danger" id="reboot-jetson-btn" onclick="rebootJetson()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-power"></i> Restart Jetson
                    </button>
                    <button class="sv-btn sv-btn-accent" id="access-terminal-btn" onclick="requestTerminalSession()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-terminal-fill"></i> Access Terminal
                    </button>
                    <button class="sv-btn sv-btn-secondary" id="view-routes-btn" onclick="openDeviceRouteModal()" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#0ea5e9,#06b6d4);border:none;color:#fff">
                        <i class="bi bi-signpost-2-fill"></i> View Routes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Remote Terminal Session Panel -->
    <div id="sv-terminal-panel" class="sv-sync-panel hidden" style="border-color: var(--accent);">
        <div class="sv-panel-header">
            <div class="sv-panel-header-title">
                <i class="bi bi-terminal-fill pulse" style="color:var(--accent)"></i>
                <span>Remote SSH Terminal Access</span>
            </div>
            <div class="sv-panel-header-actions">
                <button class="sv-btn sv-btn-danger" id="terminal-close-btn" onclick="terminateTerminalSession()">
                    <i class="bi bi-x-circle-fill"></i> Close Session
                </button>
            </div>
        </div>

        <div class="sv-panel-body-sub" style="padding: 20px;">
            <!-- Status messages -->
            <div id="terminal-status-container" style="display:flex;flex-direction:column;gap:12px;">
                <div class="sv-current-file-box" style="align-items:center;gap:12px;">
                    <div class="sv-spinner-sm" id="terminal-status-spinner"></div>
                    <span class="sv-label" style="min-width:auto">Status:</span>
                    <span id="terminal-status-text" class="mono" style="color:var(--text-primary)">Requesting terminal session...</span>
                </div>

                <!-- Ready Connection details -->
                <div id="terminal-connection-details" class="hidden" style="display:flex;flex-direction:column;gap:12px;">
                    <div style="background:var(--surface-2);border-radius:8px;padding:16px;border:1px solid var(--border)">
                        <div class="sv-label" style="margin-bottom:8px">1. SSH Command (Copy and run in your terminal):</div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" id="terminal-connection-string" class="sv-input" style="flex:1;font-family:var(--font-mono);font-size:12px;" readonly>
                            <button class="sv-btn sv-btn-secondary" onclick="copyConnectionString()" title="Copy to clipboard">
                                <i class="bi bi-clipboard"></i> Copy
                            </button>
                        </div>
                    </div>

                    <div class="sv-sync-stats-grid">
                        <div class="sv-sync-stat-box">
                            <span class="sv-sync-stat-label">Timeout</span>
                            <span class="sv-sync-stat-val" id="terminal-stat-time">60:00</span>
                        </div>
                        <div class="sv-sync-stat-box">
                            <span class="sv-sync-stat-label">Remote Port</span>
                            <span class="sv-sync-stat-val mono" id="terminal-stat-port">-</span>
                        </div>
                        <div class="sv-sync-stat-box">
                            <span class="sv-sync-stat-label">Security</span>
                            <span class="sv-sync-stat-val" style="color:var(--green);font-size:12px">Key Auth Only</span>
                        </div>
                    </div>

                    <div style="background:rgba(255, 171, 64, 0.08);border:1px solid rgba(255,171,64,0.2);border-radius:8px;padding:12px 16px;font-size:12px;color:var(--text-secondary);line-height:1.5">
                        <i class="bi bi-info-circle-fill" style="color:var(--amber);margin-right:6px"></i>
                        <span>This is a reverse SSH tunnel. The port will close automatically once the timer expires. Your local SSH key must be authorized on the control room server.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Diagnostics Panel (Test Mode) -->
    <x-diagnostic :device-id="$device['id']" />


    {{-- Camera Streams --}}
    <div class="sv-settings-section">
        <div class="sv-settings-section-title">
            <i class="bi bi-camera-video-fill"></i>
            Camera Streams ({{ count($device['cameras']) }})
        </div>

        <div class="sv-camera-grid" id="sv-camera-grid">
            @forelse ($device['cameras'] as $camera)
                <x-camera-card :camera="$camera" />
            @empty
                <div style="color:var(--text-muted);padding:40px;text-align:center">
                    <i class="bi bi-camera-video-off" style="font-size:32px;opacity:0.3;display:block;margin-bottom:12px"></i>
                    No cameras configured for this device.
                </div>
            @endforelse
        </div>
    <!-- Server Power Logs Modal -->
    <div id="power-logs-modal" class="sv-modal-backdrop hidden" onclick="closePowerLogsModal()">
        <div class="sv-modal-card" style="max-width: 650px; width: 90%; background: var(--surface-1); border-color: var(--accent);" onclick="event.stopPropagation()">
            <div class="sv-modal-header">
                <h3 class="sv-modal-title">
                    <i class="bi bi-clock-history" style="color:var(--accent)"></i>
                    Server Start/Shutdown History (UAE Time)
                </h3>
                <button class="sv-modal-close" onclick="document.getElementById('power-logs-modal').classList.add('hidden')">&times;</button>
            </div>
            <div class="sv-modal-body" style="padding: 20px; max-height: 400px; overflow-y: auto;">
                <table style="width:100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border); color: var(--text-muted); font-size: 12px;">
                            <th style="padding: 10px 8px;">#</th>
                            <th style="padding: 10px 8px;">Started At (UAE)</th>
                            <th style="padding: 10px 8px;">Stopped At (UAE)</th>
                            <th style="padding: 10px 8px;">Reason / Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($powerLogs ?? [] as $index => $log)
                            <tr style="border-bottom: 1px solid var(--border); font-size: 13px;">
                                <td style="padding: 10px 8px; color: var(--text-muted);">{{ $index + 1 }}</td>
                                <td style="padding: 10px 8px; font-family: var(--font-mono); color: var(--text-secondary);">{{ $log['started_at'] }}</td>
                                <td style="padding: 10px 8px; font-family: var(--font-mono); color: var(--text-secondary);">
                                    @if($log['stopped_at'] === 'Active Now')
                                        <span style="color: var(--green); font-weight: 600; display:inline-flex; align-items:center; gap:6px;">
                                            <span style="width: 8px; height: 8px; background: var(--green); border-radius: 50%;"></span>
                                            Active Now
                                        </span>
                                    @else
                                        {{ $log['stopped_at'] }}
                                    @endif
                                </td>
                                <td style="padding: 10px 8px; color: var(--text-muted);">{{ $log['reason'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">
                                    No power logs recorded yet for this device.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="sv-modal-footer">
                <button type="button" class="sv-btn sv-btn-secondary" onclick="document.getElementById('power-logs-modal').classList.add('hidden')">Close</button>
            </div>
        </div>
    </div>

    {{-- Route History Modal --}}
    <div id="device-route-modal" class="sv-modal-backdrop hidden" onclick="closeDeviceRouteModal(event)">
        <div class="sv-modal-card" style="max-width: 800px; width: 95%; background: var(--surface-1);" onclick="event.stopPropagation()">
            <div class="sv-modal-header">
                <h3 class="sv-modal-title">
                    <i class="bi bi-signpost-2-fill" style="color:var(--accent)"></i>
                    Route History — {{ $device['name'] }}
                </h3>
                <button class="sv-modal-close" onclick="document.getElementById('device-route-modal').classList.add('hidden')">&times;</button>
            </div>
            <div class="sv-modal-body" style="padding: 20px;">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                    <label class="sv-label" style="min-width:auto">Date:</label>
                    <input type="date" id="device-route-date" class="sv-input" style="max-width:180px" />
                    <button class="sv-btn sv-btn-secondary" onclick="loadDeviceRoute()">
                        <i class="bi bi-arrow-clockwise"></i> Load
                    </button>
                    <span id="device-route-info" style="font-size:12px;color:var(--text-muted)"></span>
                </div>
                <div id="device-route-map" style="width:100%;height:400px;border-radius:8px;border:1px solid var(--border)"></div>
            </div>
            <div class="sv-modal-footer">
                <button class="sv-btn sv-btn-secondary" onclick="document.getElementById('device-route-modal').classList.add('hidden')">Close</button>
            </div>
        </div>
    </div>

@endsection

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin="" />
    <style>
        .leaflet-popup-content-wrapper {
            background: rgba(15, 17, 23, 0.95) !important;
            backdrop-filter: blur(12px) !important;
            border: 1px solid rgba(255,255,255,0.1) !important;
            border-radius: 12px !important;
            box-shadow: 0 12px 40px rgba(0,0,0,0.5) !important;
            color: #e2e8f0 !important;
        }
        .leaflet-popup-tip { background: rgba(15, 17, 23, 0.95) !important; }
        .leaflet-popup-content { margin: 8px 12px !important; font-size: 12px; }
    </style>
@endpush

@push('scripts')
    {{-- HLS.js — browser HLS player --}}
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.7/dist/hls.min.js"></script>

    {{-- Stream player + diagnostics + sync + terminal --}}
    <script src="{{ asset('js/stream-player.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/diagnostic.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>

    <script src="{{ asset('js/terminal.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>

    {{-- Leaflet JS for route modal --}}
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>

    <script>
        const DEVICE_ID = @json($device['id']);
        let _drMap = null, _drLayer = null, _drMarkers = null;

        function openPowerLogsModal() {
            document.getElementById('power-logs-modal').classList.remove('hidden');
        }
        function closePowerLogsModal(e) {
            if (!e || e.target === document.getElementById('power-logs-modal')) {
                document.getElementById('power-logs-modal').classList.add('hidden');
            }
        }

        function openDeviceRouteModal() {
            const dateInput = document.getElementById('device-route-date');
            if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];

            document.getElementById('device-route-modal').classList.remove('hidden');

            setTimeout(() => {
                if (!_drMap) {
                    _drMap = L.map('device-route-map', {
                        center: [25.2048, 55.2708],
                        zoom: 10,
                        zoomControl: true,
                        attributionControl: false,
                    });
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                        maxZoom: 19, subdomains: 'abcd'
                    }).addTo(_drMap);
                    _drLayer = L.layerGroup().addTo(_drMap);
                    _drMarkers = L.layerGroup().addTo(_drMap);
                } else {
                    _drMap.invalidateSize();
                }
                loadDeviceRoute();
            }, 200);
        }

        function closeDeviceRouteModal(e) {
            if (!e || e.target === document.getElementById('device-route-modal')) {
                document.getElementById('device-route-modal').classList.add('hidden');
            }
        }

        function loadDeviceRoute() {
            if (!_drMap) return;
            const dateInput = document.getElementById('device-route-date');
            const date = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];
            const from = `${date} 00:00:00`;
            const to = `${date} 23:59:59`;

            fetch(`/api/surveillance/map/route/${DEVICE_ID}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
                .then(r => r.json())
                .then(data => {
                    _drLayer.clearLayers();
                    _drMarkers.clearLayers();
                    const pts = data.coordinates || [];
                    const infoEl = document.getElementById('device-route-info');

                    if (pts.length === 0) {
                        if (infoEl) infoEl.textContent = 'No data for this date.';
                        return;
                    }
                    if (infoEl) infoEl.textContent = `${pts.length} points recorded`;

                    const latLngs = pts.map(p => [p.lat, p.lng]);

                    // Draw gradient polyline
                    for (let i = 0; i < latLngs.length - 1; i++) {
                        const ratio = i / Math.max(latLngs.length - 1, 1);
                        const r = Math.round(14 + (139 - 14) * ratio);
                        const g = Math.round(165 + (92 - 165) * ratio);
                        const b = Math.round(233 + (246 - 233) * ratio);
                        L.polyline([latLngs[i], latLngs[i+1]], {
                            color: `rgb(${r},${g},${b})`, weight: 3, opacity: 0.85
                        }).addTo(_drLayer);
                    }

                    // Start / End markers
                    const mkIcon = (color) => L.divIcon({
                        className: '',
                        html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3)"></div>`,
                        iconSize: [14, 14], iconAnchor: [7, 7],
                    });
                    L.marker(latLngs[0], { icon: mkIcon('#22c55e') })
                        .bindPopup(`<b>Start</b><br>${pts[0].recorded_at}`).addTo(_drMarkers);
                    L.marker(latLngs[latLngs.length-1], { icon: mkIcon('#ef4444') })
                        .bindPopup(`<b>End</b><br>${pts[pts.length-1].recorded_at}`).addTo(_drMarkers);

                    _drMap.fitBounds(latLngs, { padding: [30, 30] });
                })
                .catch(err => console.error('Route load error:', err));
        }
    </script>
@endpush
