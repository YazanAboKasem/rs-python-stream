@extends('layouts.surveillance')

@section('title', 'Monitoring Room')
@section('page-title', 'Monitoring Room')

{{-- ── Main Content ──────────────────────────────────────────────── --}}
@section('content')

    <div class="sv-section-header" style="display:flex;align-items:center;margin-bottom:24px">
        <div>
            <h1 class="sv-section-title">Live Cameras</h1>
            @php
                $totalCams = $devices->sum(fn($d) => count($d['cameras']));
            @endphp
            <span class="sv-section-count">{{ $totalCams }} cameras · {{ $devices->count() }} {{ $devices->count() === 1 ? 'device' : 'devices' }}</span>
        </div>
    </div>

    {{-- Camera groups — each Jetson device as a bordered group --}}
    @forelse ($devices as $device)
        <x-device-group :device="$device" />
    @empty
        <div style="color:var(--text-muted);padding:60px;text-align:center">
            <i class="bi bi-cpu" style="font-size:48px;opacity:0.2;display:block;margin-bottom:16px"></i>
            No devices configured. Add devices in <code>config/surveillance.php</code>.
        </div>
    @endforelse

@endsection

@push('scripts')
    {{-- HLS.js — browser HLS player (Chrome, Firefox, Edge) --}}
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.7/dist/hls.min.js"></script>

    {{-- Stream player v3 — versioned to bust cache --}}
    <script src="{{ asset('js/stream-player.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/diagnostic.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
    <script src="{{ asset('js/qnap-sync.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
@endpush
