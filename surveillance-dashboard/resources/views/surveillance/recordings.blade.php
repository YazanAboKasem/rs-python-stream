@extends('layouts.surveillance')

@section('title', 'Uploaded Recordings')
@section('page-title', 'Uploaded Recordings')

@section('content')

    {{-- Breadcrumb --}}
    <div class="sv-breadcrumb">
        <a href="{{ route('surveillance.index') }}"><i class="bi bi-grid-3x3-gap-fill"></i> Monitoring Room</a>
        <i class="bi bi-chevron-right"></i>
        <span>Uploaded Recordings</span>
    </div>

    <div class="sv-settings-section">
        <div class="sv-settings-card">
            <div class="sv-settings-card-header">
                <div class="sv-settings-card-title">
                    <i class="bi bi-collection-play-fill" style="color:var(--accent)"></i>
                    Browse Uploaded Recordings on Server
                </div>
            </div>
            <div class="sv-settings-card-body" style="padding: 24px;">
                @forelse ($uploadedDevices as $device)
                    <div style="margin-bottom: 30px; background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: 8px; padding: 20px;">
                        <h3 style="margin: 0 0 16px 0; display:flex; align-items:center; gap:8px; border-bottom: 1px solid var(--border); padding-bottom: 10px; color: var(--accent);">
                            <i class="bi bi-cpu-fill"></i> Device: {{ $device['device_id'] }}
                        </h3>

                        @foreach ($device['cameras'] as $camera)
                            <div style="margin-bottom: 20px; padding-left: 10px;">
                                <h4 style="margin: 0 0 12px 0; color: var(--text-primary); font-size: 15px; display:flex; align-items:center; gap:6px;">
                                    <i class="bi bi-camera-video-fill" style="color: var(--green);"></i> Camera ID: {{ $camera['camera_id'] }}
                                </h4>

                                @foreach ($camera['dates'] as $dateGroup)
                                    <div style="margin-bottom: 16px; border: 1px solid rgba(255,255,255,0.05); border-radius: 6px; overflow: hidden; background: rgba(0,0,0,0.15)">
                                        <div style="background: rgba(255,255,255,0.03); padding: 8px 12px; font-weight: 600; font-size: 13px; color: var(--text-secondary); border-bottom: 1px solid rgba(255,255,255,0.05); display:flex; align-items:center; gap:6px;">
                                            <i class="bi bi-calendar-event"></i> {{ $dateGroup['date'] }}
                                        </div>
                                        <div style="padding: 6px 12px;">
                                            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                                                <thead>
                                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); color: var(--text-muted); font-size: 11px;">
                                                        <th style="padding: 6px 8px;">File Name</th>
                                                        <th style="padding: 6px 8px; width: 120px;">Size</th>
                                                        <th style="padding: 6px 8px; width: 160px; text-align: right;">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($dateGroup['files'] as $file)
                                                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 13px;">
                                                            <td style="padding: 8px; font-family: var(--font-mono); color: var(--text-secondary);">{{ $file['display_name'] }}</td>
                                                            <td style="padding: 8px; color: var(--text-muted);">{{ $file['size'] }}</td>
                                                            <td style="padding: 8px; text-align: right;">
                                                                <button class="sv-btn sv-btn-secondary" style="padding: 4px 8px; font-size: 12px; display:inline-flex; align-items:center; gap:4px;" onclick="playUploadedVideo('{{ $file['play_url'] }}', '{{ $file['name'] }}')">
                                                                    <i class="bi bi-play-fill"></i> Play
                                                                </button>
                                                                <a class="sv-btn sv-btn-secondary" style="padding: 4px 8px; font-size: 12px; display:inline-flex; align-items:center; gap:4px; text-decoration:none;" href="{{ $file['play_url'] }}" download>
                                                                    <i class="bi bi-download"></i> Download
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @empty
                    <div style="color:var(--text-muted);padding:40px;text-align:center">
                        <i class="bi bi-cloud-slash" style="font-size:48px;opacity:0.3;display:block;margin-bottom:12px"></i>
                        No recordings uploaded to the server yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Video Playback Modal -->
    <div id="video-playback-modal" class="sv-modal-backdrop hidden" onclick="closeUploadedVideo(event)">
        <div class="sv-modal-card" style="max-width: 800px; width: 90%; background: var(--surface-1); border-color: var(--accent);" onclick="event.stopPropagation()">
            <div class="sv-modal-header">
                <h3 class="sv-modal-title" id="video-modal-title">
                    <i class="bi bi-play-btn-fill" style="color:var(--accent)"></i>
                    Playing Recording
                </h3>
                <button class="sv-modal-close" onclick="closeUploadedVideo(null)">&times;</button>
            </div>
            <div class="sv-modal-body" style="padding: 0; background: #000; display: flex; justify-content: center; align-items: center; aspect-ratio: 16/9; overflow: hidden;">
                <video id="uploaded-video-player" controls autoplay style="width: 100%; height: 100%; object-fit: contain;"></video>
            </div>
            <div class="sv-modal-footer" style="padding: 12px 16px;">
                <span id="video-modal-filename" class="mono text-muted" style="font-size: 12px;"></span>
                <button type="button" class="sv-btn sv-btn-secondary" onclick="closeUploadedVideo(null)">Close</button>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        function playUploadedVideo(url, filename) {
            const modal = document.getElementById('video-playback-modal');
            const player = document.getElementById('uploaded-video-player');
            const title = document.getElementById('video-modal-filename');
            
            if (modal && player) {
                player.src = url;
                if (title) title.textContent = filename;
                modal.classList.remove('hidden');
                player.play().catch(err => console.log('Auto-play blocked or failed:', err));
            }
        }

        function closeUploadedVideo(event) {
            // Close if background clicked or explicitly triggered
            if (!event || event.target === document.getElementById('video-playback-modal')) {
                const modal = document.getElementById('video-playback-modal');
                const player = document.getElementById('uploaded-video-player');
                
                if (modal && player) {
                    player.pause();
                    player.src = '';
                    modal.classList.add('hidden');
                }
            }
        }
    </script>
@endpush
