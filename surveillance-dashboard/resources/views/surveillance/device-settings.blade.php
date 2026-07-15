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
                    <button class="sv-btn sv-btn-secondary" id="sync-recordings-btn" onclick="openSyncModal()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-cloud-arrow-up-fill"></i> Sync Recordings
                    </button>
                    <button class="sv-btn sv-btn-secondary" id="test-mode-toggle-btn" onclick="toggleTestMode()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-cpu-fill"></i> Test Mode
                    </button>
                    <button class="sv-btn sv-btn-danger" id="reboot-jetson-btn" onclick="rebootJetson()" style="display:inline-flex;align-items:center;gap:8px">
                        <i class="bi bi-power"></i> Restart Jetson
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Diagnostics Panel (Test Mode) -->
    <x-diagnostic />

    <!-- Sync Progress Panel -->
    <x-sync-progress />

    <!-- Sync Modal -->
    <x-qnap-sync-modal />

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
    </div>

@endsection

@push('scripts')
    {{-- HLS.js — browser HLS player --}}
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.7/dist/hls.min.js"></script>

    {{-- Stream player + diagnostics + sync --}}
    <script src="{{ asset('js/stream-player.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/diagnostic.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/qnap-sync.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
@endpush
