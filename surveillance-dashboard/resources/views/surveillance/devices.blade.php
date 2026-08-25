@extends('layouts.surveillance')

@section('title', 'Devices')
@section('page-title', 'Devices')

@section('content')

    <div class="sv-section-header" style="display:flex;align-items:center;margin-bottom:24px">
        <div>
            <h1 class="sv-section-title">Devices</h1>
            <span class="sv-section-count">{{ $devices->count() }} {{ $devices->count() === 1 ? 'device' : 'devices' }} registered</span>
        </div>
    </div>

    {{-- Discovered / Unregistered Devices --}}
    <div class="sv-section-header" id="sv-pending-devices-section" style="{{ $pendingDevices->isEmpty() ? 'display:none' : '' }};margin-bottom:12px">
        <div>
            <h2 class="sv-section-title" style="font-size:16px">
                <i class="bi bi-broadcast" style="color:var(--amber)"></i>
                Discovered Devices
            </h2>
            <span class="sv-section-count">Connected but not yet registered</span>
        </div>
    </div>
    <div class="sv-devices-list" id="sv-pending-devices-list" style="margin-bottom:28px">
        @foreach ($pendingDevices as $pending)
            <div class="sv-device-card pending" id="pending-device-card-{{ $pending->device_id }}">
                <div class="sv-device-card-header">
                    <div class="sv-device-card-info">
                        <div class="sv-device-status-indicator online">
                            <span class="sv-device-status-dot"></span>
                        </div>
                        <div class="sv-device-card-text">
                            <div class="sv-device-card-name mono">{{ $pending->device_id }}</div>
                            <div class="sv-device-card-meta">
                                <i class="bi bi-hdd-network"></i> {{ $pending->hostname ?? 'Unknown host' }}
                                <span class="sv-device-card-sep">·</span>
                                Last seen {{ $pending->last_seen_at?->diffForHumans() }}
                            </div>
                        </div>
                    </div>

                    <button class="sv-btn sv-btn-accent"
                        onclick="openDeviceRegisterModal('{{ $pending->device_id }}')">
                        <i class="bi bi-plus-circle-fill"></i>
                        Register
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Devices List --}}
    <div class="sv-devices-list">
        @forelse ($devices as $device)
            <div class="sv-device-card {{ $device['is_online'] ? 'online' : 'offline' }}" id="device-card-{{ $device['id'] }}">

                {{-- Device Header --}}
                <div class="sv-device-card-header">
                    <div class="sv-device-card-info">
                        <div class="sv-device-status-indicator {{ $device['is_online'] ? 'online' : 'offline' }}">
                            <span class="sv-device-status-dot"></span>
                        </div>
                        <div class="sv-device-card-text">
                            <div class="sv-device-card-name">{{ $device['name'] }}</div>
                            <div class="sv-device-card-meta">
                                <i class="bi bi-geo-alt-fill"></i> {{ $device['location'] ?? 'Unknown' }}
                                <span class="sv-device-card-sep">·</span>
                                <i class="bi bi-camera-video"></i> {{ count($device['cameras']) }} cameras
                                <span class="sv-device-card-sep">·</span>
                                <span class="sv-device-status-text {{ $device['is_online'] ? 'online' : 'offline' }}">
                                    {{ $device['is_online'] ? 'ONLINE' : 'OFFLINE' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:8px">
                        <button class="sv-btn sv-btn-secondary" onclick="openDeviceEditModal('{{ $device['id'] }}')">
                            <i class="bi bi-pencil-fill"></i>
                            Edit
                        </button>
                        <a href="{{ route('surveillance.device-settings', $device['id']) }}"
                           class="sv-btn sv-btn-secondary sv-device-settings-btn">
                            <i class="bi bi-gear-fill"></i>
                            Settings
                        </a>
                        <button class="sv-btn sv-btn-danger" onclick="deleteDevice('{{ $device['id'] }}', '{{ addslashes($device['name']) }}')" title="Delete device">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </div>
                </div>

                {{-- Device Details (expandable) --}}
                <div class="sv-device-card-details">
                    <div class="sv-device-detail-grid">
                        <div class="sv-device-detail-item">
                            <span class="sv-device-detail-label">Device ID</span>
                            <span class="sv-device-detail-value mono">{{ $device['id'] }}</span>
                        </div>
                        <div class="sv-device-detail-item">
                            <span class="sv-device-detail-label">Host</span>
                            <span class="sv-device-detail-value mono">{{ $device['host'] }}:{{ $device['hls_port'] }}</span>
                        </div>
                        <div class="sv-device-detail-item">
                            <span class="sv-device-detail-label">Cameras</span>
                            <span class="sv-device-detail-value">
                                @foreach($device['cameras'] as $cam)
                                    <span class="sv-device-cam-tag">{{ $cam['label'] }}</span>
                                @endforeach
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        @empty
            <div class="sv-empty-state">
                <i class="bi bi-cpu" style="font-size:48px;opacity:0.2"></i>
                <p>No devices registered yet.</p>
                <p class="sv-empty-hint">Power on a device — once it connects it will appear above under "Discovered Devices" ready to register.</p>
            </div>
        @endforelse
    </div>

    <x-device-register-modal />

@endsection

@push('scripts')
    <script src="{{ asset('js/device-register.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
@endpush
