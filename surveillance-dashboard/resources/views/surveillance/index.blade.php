@extends('layouts.surveillance')

@section('title', 'Live Surveillance')

{{-- ── Sidebar ──────────────────────────────────────────────────── --}}
@section('sidebar')

    {{-- Future AI Alerts Panel --}}
    <div class="sv-sidebar-section">
        <div class="sv-sidebar-heading">
            <i class="bi bi-bell-fill sv-sidebar-heading-icon"></i>
            AI Alerts
            <span class="sv-placeholder-tag" style="margin-left:auto">Phase 2</span>
        </div>
        <div class="sv-sidebar-body">
            <div class="sv-placeholder-alert">
                <div class="sv-placeholder-alert-icon">
                    <i class="bi bi-robot"></i>
                </div>
                <div class="sv-placeholder-alert-text">
                    AI event detection will appear here.<br>
                    Python stream service will POST alerts to this dashboard.
                </div>
            </div>
        </div>
    </div>

    {{-- System Info Panel --}}
    <div class="sv-sidebar-section">
        <div class="sv-sidebar-heading">
            <i class="bi bi-activity sv-sidebar-heading-icon"></i>
            System
        </div>
        <div class="sv-sidebar-body">
            <div class="sv-info-row">
                <span class="sv-info-label">Stream server</span>
                <span class="sv-info-value">{{ config('surveillance.media_server.host') }}</span>
            </div>
            <div class="sv-info-row">
                <span class="sv-info-label">HLS port</span>
                <span class="sv-info-value">{{ config('surveillance.media_server.hls_port') }}</span>
            </div>
            <div class="sv-info-row">
                <span class="sv-info-label">WebRTC port</span>
                <span class="sv-info-value">{{ config('surveillance.media_server.webrtc_port') }}</span>
            </div>
            <div class="sv-info-row">
                <span class="sv-info-label">Cameras active</span>
                <span class="sv-info-value online">{{ $cameras->count() }}</span>
            </div>
            <div class="sv-info-row">
                <span class="sv-info-label">Phase</span>
                <span class="sv-info-value">1 — Streaming</span>
            </div>
        </div>
    </div>

    {{-- Future Notifications placeholder --}}
    <div class="sv-sidebar-section">
        <div class="sv-sidebar-heading">
            <i class="bi bi-chat-dots sv-sidebar-heading-icon"></i>
            Notifications
            <span class="sv-placeholder-tag" style="margin-left:auto">Phase 3</span>
        </div>
        <div class="sv-sidebar-body">
            <div class="sv-placeholder-alert">
                <div class="sv-placeholder-alert-icon">
                    <i class="bi bi-envelope-slash"></i>
                </div>
                <div class="sv-placeholder-alert-text">
                    Push and email notifications will be configured in Phase 3.
                </div>
            </div>
        </div>
    </div>

@endsection

{{-- ── Main Content ──────────────────────────────────────────────── --}}
@section('content')

    <div class="sv-section-header" style="display:flex;align-items:center;margin-bottom:24px">
        <div>
            <h1 class="sv-section-title">Live Cameras</h1>
            <span class="sv-section-count">{{ $cameras->count() }} online</span>
        </div>
        <div style="display:flex;gap:12px;margin-left:auto">
            <button class="sv-btn sv-btn-secondary" id="sync-recordings-btn" onclick="openSyncModal()" style="display:inline-flex;align-items:center;gap:8px">
                <i class="bi bi-cloud-arrow-up-fill"></i> Sync Recordings
            </button>
            <button class="sv-btn sv-btn-secondary" id="test-mode-toggle-btn" onclick="toggleTestMode()" style="display:inline-flex;align-items:center;gap:8px">
                <i class="bi bi-cpu-fill"></i> Test Mode
            </button>
            <button class="sv-btn sv-btn-danger" id="reboot-jetson-btn" onclick="rebootJetson()" style="display:inline-flex;align-items:center;gap:8px" disabled>
                <i class="bi bi-power"></i> Restart Jetson
            </button>
        </div>
    </div>

    <!-- Diagnostics Panel (Test Mode) -->
    <x-diagnostic />

    <!-- Sync Progress Panel -->
    <x-sync-progress />

    <!-- Sync Modal -->
    <x-qnap-sync-modal />

    {{--
        Camera grid — loop driven by config/surveillance.php.
        To add a camera: add an entry there. No Blade changes needed.
    --}}
    <div class="sv-camera-grid" id="sv-camera-grid">
        @forelse ($cameras as $camera)
            <x-camera-card :camera="$camera" />
        @empty
            <div style="color:var(--text-muted);padding:40px;text-align:center">
                <i class="bi bi-camera-video-off" style="font-size:32px;opacity:0.3;display:block;margin-bottom:12px"></i>
                No cameras configured. Add cameras in <code>config/surveillance.php</code>.
            </div>
        @endforelse
    </div>

@endsection

@push('scripts')
    {{-- HLS.js — browser HLS player (Chrome, Firefox, Edge) --}}
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.7/dist/hls.min.js"></script>

    {{-- Stream player v3 — versioned to bust cache --}}
    <script src="{{ asset('js/stream-player.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/diagnostic.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/qnap-sync.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
@endpush
