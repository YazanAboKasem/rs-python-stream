{{--
    Device Group Component — wraps a Jetson's cameras in a bordered group
    ─────────────────────────────────────────────────────────────────
    Props ($device array):
      id       'jetson-1'
      name     'Jetson Orin — Main Gate'
      location 'Front Entrance'
      cameras  [...resolved camera arrays...]
      is_online bool
--}}
@props(['device'])

@php
    $isOnline = $device['is_online'] ?? false;
@endphp

<div class="sv-device-group {{ $isOnline ? 'online' : 'offline' }}" id="device-group-{{ $device['id'] }}">
    <div class="sv-device-group-header">
        <div class="sv-device-group-info">
            <div class="sv-device-group-status-dot {{ $isOnline ? 'online' : 'offline' }}"></div>
            <div>
                <div class="sv-device-group-name">{{ $device['name'] }}</div>
                <div class="sv-device-group-location">
                    <i class="bi bi-geo-alt-fill"></i>
                    {{ $device['location'] ?? 'Unknown' }}
                    · {{ count($device['cameras']) }} {{ count($device['cameras']) === 1 ? 'camera' : 'cameras' }}
                </div>
            </div>
        </div>
        <a href="{{ route('surveillance.device-settings', $device['id']) }}"
           class="sv-device-group-settings-btn"
           title="Device Settings">
            <i class="bi bi-gear-fill"></i>
        </a>
    </div>

    <div class="sv-device-group-cameras">
        @forelse ($device['cameras'] as $camera)
            <x-camera-card :camera="$camera" />
        @empty
            <div class="sv-device-group-empty">
                <i class="bi bi-camera-video-off"></i>
                No cameras configured for this device.
            </div>
        @endforelse
    </div>
</div>
