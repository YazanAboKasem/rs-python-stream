@extends('layouts.surveillance')

@section('title', 'Devices')
@section('page-title', 'Devices')

@section('content')

    <div class="sv-section-header" style="display:flex;align-items:center;margin-bottom:24px">
        <div>
            <h1 class="sv-section-title">Jetson Devices</h1>
            <span class="sv-section-count">{{ $devices->count() }} {{ $devices->count() === 1 ? 'device' : 'devices' }} registered</span>
        </div>
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

                    <a href="{{ route('surveillance.device-settings', $device['id']) }}"
                       class="sv-btn sv-btn-secondary sv-device-settings-btn">
                        <i class="bi bi-gear-fill"></i>
                        Settings
                    </a>
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
                <p>No devices configured.</p>
                <p class="sv-empty-hint">Add devices in <code>config/surveillance.php</code></p>
            </div>
        @endforelse
    </div>

@endsection
