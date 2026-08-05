@extends('layouts.surveillance')

@section('title', $device['name'] . ' — Sync Recordings')
@section('page-title', 'Sync Recordings — ' . $device['name'])

@section('content')

    {{-- Breadcrumb --}}
    <div class="sv-breadcrumb">
        <a href="{{ route('surveillance.devices') }}"><i class="bi bi-cpu-fill"></i> Devices</a>
        <i class="bi bi-chevron-right"></i>
        <a href="{{ route('surveillance.device-settings', $device['id']) }}">{{ $device['name'] }}</a>
        <i class="bi bi-chevron-right"></i>
        <span>Sync Recordings</span>
    </div>

    <div class="sv-settings-grid" style="display: grid; grid-template-columns: 1fr; gap: 24px; max-width: 900px; margin: 0 auto;">
        
        {{-- Device Status Indicator (Simple header card) --}}
        <div class="sv-settings-card" style="border-color: var(--accent);">
            <div class="sv-settings-card-body" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="bi bi-cloud-arrow-up-fill" style="font-size: 24px; color: var(--accent);"></i>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; font-weight: 600;">{{ $device['name'] }} Sync Agent</h4>
                        <span style="font-size: 12px; color: var(--text-muted);">{{ $device['location'] ?? 'Location: Front Entrance' }} · {{ $device['host'] }}</span>
                    </div>
                </div>
                <div class="sv-device-status-pill {{ $device['is_online'] ? 'online' : 'offline' }}">
                    <span class="sv-status-dot"></span>
                    {{ $device['is_online'] ? 'ONLINE' : 'OFFLINE' }}
                </div>
            </div>
        </div>

        {{-- 1. Sync Configuration Card --}}
        <div class="sv-settings-card" id="sync-config-card">
            <div class="sv-settings-card-header">
                <div class="sv-settings-card-title">
                    <i class="bi bi-sliders"></i>
                    Sync Filters & Scope
                </div>
            </div>
            <div class="sv-settings-card-body" style="padding: 24px;">
                <form id="sync-scan-form" onsubmit="event.preventDefault();">
                    
                    {{-- Scope Option --}}
                    <div class="sv-form-group" style="margin-bottom: 20px;">
                        <label class="sv-label" style="display: block; margin-bottom: 8px; font-weight: 600;">Sync Scope</label>
                        <div class="sv-radio-group" style="display: flex; flex-direction: column; gap: 10px;">
                            <label class="sv-radio-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="radio" name="sync-scope" value="all" checked onchange="toggleScopeInputs(); scanFiles()">
                                <span>All recordings</span>
                            </label>
                            <label class="sv-radio-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="radio" name="sync-scope" value="today" onchange="toggleScopeInputs(); scanFiles()">
                                <span>Only today's recordings</span>
                            </label>
                            <label class="sv-radio-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="radio" name="sync-scope" value="last_n_days" onchange="toggleScopeInputs(); scanFiles()">
                                <span>Only last N days:</span>
                                <input type="number" id="sync-days" class="sv-input" style="width: 80px; padding: 4px 8px; display: inline-block; margin-left: 8px; height: auto;" value="7" min="1" disabled onchange="scanFiles()" oninput="scanFiles()">
                            </label>
                            <label class="sv-radio-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="radio" name="sync-scope" value="cameras" onchange="toggleScopeInputs(); scanFiles()">
                                <span>Only specific cameras</span>
                            </label>
                        </div>
                    </div>

                    {{-- Camera Checklist (hidden by default) --}}
                    <div class="sv-form-group hidden" id="cameras-selection-row" style="margin-bottom: 20px; padding-left: 20px; border-left: 2px solid var(--border);">
                        <label class="sv-label" style="display: block; margin-bottom: 8px; font-weight: 600;">Select Cameras</label>
                        <div class="sv-checkbox-group" style="display: flex; flex-direction: column; gap: 8px;">
                            @foreach($device['cameras'] as $cam)
                                @if($cam['enabled'] ?? false)
                                <label class="sv-checkbox-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="sync-cameras" value="{{ $cam['id'] }}" checked onchange="scanFiles()">
                                    <span>{{ $cam['label'] }}</span>
                                </label>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    {{-- Post-upload Options --}}
                    <div class="sv-form-group" style="margin-bottom: 24px; border-top: 1px solid var(--border); padding-top: 20px;">
                        <label class="sv-label" style="display: block; margin-bottom: 10px; font-weight: 600;">Upload Behavior</label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label class="sv-checkbox-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="delete-after-upload" checked>
                                <span>Delete local files from Jetson after successful upload</span>
                            </label>
                            <label class="sv-checkbox-label" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="overwrite-existing">
                                <span>Overwrite existing files on server</span>
                            </label>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 20px;">
                        <a href="{{ route('surveillance.device-settings', $device['id']) }}" class="sv-btn sv-btn-secondary" style="text-decoration:none">Back to Settings</a>
                        <div id="scanning-indicator" class="hidden" style="color: var(--accent); font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-arrow-repeat" style="display:inline-block; animation:sv-spin 1s linear infinite;"></i>
                            <span>Scanning Jetson recordings...</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- 2. Scanned Files Listing Card (hidden by default) --}}
        <div class="sv-settings-card hidden" id="scanned-files-card">
            <div class="sv-settings-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div class="sv-settings-card-title">
                    <i class="bi bi-file-earmark-play-fill"></i>
                    Files Ready to Sync (Selected: <span id="selected-files-count">0</span> / <span id="scanned-files-count">0</span>, Size: <span id="selected-files-size">0.0 MB</span> / <span id="scanned-files-size">0.0 MB</span>)
                </div>
                <button class="sv-btn sv-btn-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="rescanFiles()">
                    <i class="bi bi-arrow-repeat"></i> Reload
                </button>
            </div>
            <div class="sv-settings-card-body" style="padding: 20px;">
                <div style="border: 1px solid var(--border); border-radius: 6px; background: rgba(0,0,0,0.15); max-height: 250px; overflow-y: auto; margin-bottom: 20px;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-family: var(--font-mono); font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border); color: var(--text-muted); font-size: 11px;">
                                <th style="padding: 8px 12px; width: 40px; text-align: center;">
                                    <input type="checkbox" id="select-all-files-checkbox" checked onchange="toggleSelectAllFiles(this)">
                                </th>
                                <th style="padding: 8px 12px;">File Path</th>
                                <th style="padding: 8px 12px; text-align: right; width: 100px;">Duration</th>
                                <th style="padding: 8px 12px; text-align: right; width: 120px;">Size</th>
                            </tr>
                        </thead>
                        <tbody id="scanned-files-tbody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <button class="sv-btn sv-btn-accent" style="background: var(--green); border-color: var(--green);" id="start-sync-btn" onclick="startSynchronize()">
                        <i class="bi bi-cloud-arrow-up-fill"></i> Start Sync Now
                    </button>
                </div>
            </div>
        </div>

        {{-- 3. Sync Progress Panel --}}
        <x-sync-progress />

    </div>

@endsection

@push('scripts')
    <script>
        // Export device variables to window for access in JS
        window.CURRENT_DEVICE_ID = @json($device['id']);
    </script>
    <script src="{{ asset('js/qnap-sync-page.js') }}?v={{ config('surveillance.asset_version', '1') }}"></script>
@endpush
