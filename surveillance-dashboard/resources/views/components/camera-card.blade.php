{{--
    Camera Card Component — v2 with Stream Stats + Controls
    ─────────────────────────────────────────────────────────────────
    Props ($camera array keys):
      id         'cam1'
      label      'Camera 1 — Front View'
      path       'cam1'         (HD MediaMTX path)
      path_sub   'cam1_sub'     (SD MediaMTX path)
      hls_url    'https://...cam1/index.m3u8'     (default = HD)
      hls_url_hd 'https://...cam1/index.m3u8'
      hls_url_sd 'https://...cam1_sub/index.m3u8'
      webrtc_url 'https://...cam1'
      ip         '192.168.1.64'
      ptz        true|false

    To add a camera: add it to config/surveillance.php only.
--}}
@props(['camera'])

@php
    $id        = $camera['id'];
    $hasPtz    = $camera['ptz'] ?? false;
    $camIp     = $camera['ip'] ?? '';
    $hlsHd     = $camera['hls_url_hd']    ?? $camera['hls_url'];
    $hlsSd     = $camera['hls_url_sd']    ?? $camera['hls_url'];
    $hlsUltra  = $camera['hls_url_ultra'] ?? $camera['hls_url_sd'] ?? $camera['hls_url'];
    $hlsLive   = $camera['hls_url_live']   ?? $camera['hls_url'];
    $currQuality = $camera['current_quality'] ?? 'hd';
    $currFps   = (int) ($camera['current_fps'] ?? 15);
@endphp

<div class="sv-camera-card" id="card-{{ $id }}">

    {{-- ── Card Header ─────────────────────────────────────── --}}
    <div class="sv-card-header">
        <div class="sv-card-title-row">
            <i class="bi bi-camera-video sv-card-icon"></i>
            <div>
                <div class="sv-card-title">{{ $camera['label'] }}</div>
                <div class="sv-card-id">{{ $id }} · {{ $camIp ?: 'local' }}</div>
            </div>
        </div>

        <div class="sv-card-header-actions">
            {{-- Controls toggle --}}
            <button
                class="sv-panel-toggle"
                id="ctrl-toggle-{{ $id }}"
                onclick="toggleControls('{{ $id }}')"
                title="Camera controls"
            >
                <i class="bi bi-sliders"></i>
                Controls
                <span class="sv-toggle-arrow">▾</span>
            </button>

            {{-- LIVE badge --}}
            <div class="sv-live-badge" id="live-badge-{{ $id }}" style="opacity:0.3">
                <span class="sv-live-dot"></span>
                LIVE
            </div>
        </div>
    </div>

    {{-- ── Video Player ─────────────────────────────────────── --}}
    <div class="sv-video-wrap" id="wrap-{{ $id }}">

        {{-- Connecting overlay --}}
        <div class="sv-video-overlay" id="overlay-{{ $id }}">
            <div class="sv-spinner"></div>
            <span class="sv-overlay-msg">Connecting to stream…</span>
        </div>

        {{-- Scanline effect --}}
        <div class="sv-scanlines"></div>

        <video
            id="player-{{ $id }}"
            class="sv-video"
            data-camera-id="{{ $id }}"
            data-hls-url="{{ $hlsLive }}"
            data-hls-url-hd="{{ $hlsHd }}"
            data-hls-url-sd="{{ $hlsSd }}"
            data-hls-url-ultra="{{ $hlsUltra }}"
            data-hls-url-live="{{ $hlsLive }}"
            data-current-quality="{{ $currQuality }}"
            data-current-fps="{{ $currFps }}"
            data-webrtc-url="{{ $camera['webrtc_url'] }}"
            data-camera-ip="{{ $camIp }}"
            data-ptz="{{ $hasPtz ? 'true' : 'false' }}"
            autoplay
            muted
            playsinline
            preload="none"
        ></video>

        {{-- Live timestamp watermark --}}
        <div class="sv-video-timestamp" id="ts-{{ $id }}">––:––:––</div>
    </div>

    {{-- ── Stream Stats Bar ─────────────────────────────────── --}}
    <div class="sv-stats-bar" id="stats-bar-{{ $id }}">
        <div class="sv-stat-item">
            <span class="sv-stat-label">Bitrate</span>
            <span class="sv-stat-value muted" id="stat-bitrate-{{ $id }}">–</span>
            <div class="sv-bitrate-bar">
                <div class="sv-bitrate-fill" id="stat-bitrate-bar-{{ $id }}" style="width:0%"></div>
            </div>
        </div>
        <div class="sv-stat-item">
            <span class="sv-stat-label">FPS</span>
            <span class="sv-stat-value muted" id="stat-fps-{{ $id }}">–</span>
        </div>
        <div class="sv-stat-item">
            <span class="sv-stat-label">Buffer</span>
            <span class="sv-stat-value muted" id="stat-buffer-{{ $id }}">–</span>
        </div>
        <div class="sv-stat-item">
            <span class="sv-stat-label">Resolution</span>
            <span class="sv-stat-value muted" id="stat-res-{{ $id }}">–</span>
        </div>
    </div>

    {{-- ── Controls Panel (collapsible) ────────────────────── --}}
    <div class="sv-controls-panel" id="ctrl-panel-{{ $id }}">
        <div class="sv-controls-inner">

            {{-- ── Row 1: Quality ─────────────────────────────── --}}
            <div class="sv-ctrl-row">
                <span class="sv-ctrl-label">Quality</span>
                <div class="sv-quality-btns" id="quality-btns-{{ $id }}">
                    <button class="sv-quality-btn{{ $currQuality === 'hd' ? ' active' : '' }}" data-quality="hd"    data-camera="{{ $id }}" title="HD Main stream (~2-6 Mbps)">
                        HD
                        <span class="sv-quality-hint">~5Mbps</span>
                    </button>
                    <button class="sv-quality-btn{{ $currQuality === 'sd' ? ' active' : '' }}" data-quality="sd"    data-camera="{{ $id }}" title="SD Sub stream (~300-800 Kbps)">
                        SD
                        <span class="sv-quality-hint">~500K</span>
                    </button>
                    <button class="sv-quality-btn{{ $currQuality === 'ultra' ? ' active' : '' }}" data-quality="ultra" data-camera="{{ $id }}" title="Ultra-low: 360p @ 5fps (~150 Kbps) — requires FFmpeg">
                        Ultra
                        <span class="sv-quality-hint">~150K</span>
                    </button>
                </div>
            </div>

            {{-- ── Row 2: FPS + Zoom ───────────────────────────── --}}
            <div class="sv-ctrl-row">
                <span class="sv-ctrl-label">Refresh</span>
                <div class="sv-fps-btns" id="fps-btns-{{ $id }}">
                    <button class="sv-fps-btn{{ $currFps === 30 ? ' active' : '' }}" data-fps="30" data-camera="{{ $id }}">Full</button>
                    <button class="sv-fps-btn{{ $currFps === 15 ? ' active' : '' }}" data-fps="15" data-camera="{{ $id }}">15fps</button>
                    <button class="sv-fps-btn{{ $currFps === 10 ? ' active' : '' }}" data-fps="10" data-camera="{{ $id }}">10fps</button>
                    <button class="sv-fps-btn{{ $currFps === 5 ? ' active' : '' }}"  data-fps="5"  data-camera="{{ $id }}">5fps</button>
                    <button class="sv-fps-btn{{ $currFps === 1 ? ' active' : '' }}"  data-fps="1"  data-camera="{{ $id }}">1fps</button>
                </div>
                <span class="sv-ctrl-label" style="min-width:auto;margin-left:auto">Zoom</span>
                <div class="sv-zoom-wrap" style="max-width:140px">
                    <button class="sv-zoom-btn" onclick="adjustZoom('{{ $id }}', -0.25)" title="Zoom out">−</button>
                    <input type="range" class="sv-zoom-slider" id="zoom-slider-{{ $id }}"
                        min="1" max="3" step="0.05" value="1"
                        oninput="applyZoom('{{ $id }}', this.value)">
                    <button class="sv-zoom-btn" onclick="adjustZoom('{{ $id }}', +0.25)" title="Zoom in">+</button>
                    <span class="sv-zoom-level" id="zoom-label-{{ $id }}">1×</span>
                </div>
            </div>

            {{-- ── Row 3: PTZ ──────────────────────────────────── --}}
            <div class="sv-ctrl-row">
                <span class="sv-ctrl-label">PTZ</span>
                @if($hasPtz)
                <div class="sv-ptz-section">
                    <div class="sv-ptz-pad">
                        {{-- Row 1 --}}
                        <div></div>
                        <button class="sv-ptz-btn" data-ptz="tilt_up"    data-cam="{{ $id }}" title="Tilt Up">▲</button>
                        <div></div>
                        {{-- Row 2 --}}
                        <button class="sv-ptz-btn" data-ptz="pan_left"   data-cam="{{ $id }}" title="Pan Left">◀</button>
                        <div class="sv-ptz-btn center" title="Stop">●</div>
                        <button class="sv-ptz-btn" data-ptz="pan_right"  data-cam="{{ $id }}" title="Pan Right">▶</button>
                        {{-- Row 3 --}}
                        <div></div>
                        <button class="sv-ptz-btn" data-ptz="tilt_down"  data-cam="{{ $id }}" title="Tilt Down">▼</button>
                        <div></div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px">
                        <button class="sv-ptz-btn" style="width:60px;height:28px;font-size:11px" data-ptz="zoom_in"  data-cam="{{ $id }}" title="Zoom In (Camera)">🔍+</button>
                        <button class="sv-ptz-btn" style="width:60px;height:28px;font-size:11px" data-ptz="zoom_out" data-cam="{{ $id }}" title="Zoom Out (Camera)">🔍−</button>
                        <button class="sv-ptz-btn" style="width:60px;height:28px;font-size:11px" data-ptz="home"     data-cam="{{ $id }}" title="Go Home">⌂</button>
                    </div>
                    <div style="font-size:10px;color:var(--text-muted);line-height:1.5;max-width:120px">
                        Physical PTZ via camera ISAPI.<br>
                        Requires <code style="font-size:9px;color:var(--accent)">camera-control.py</code> running locally.
                    </div>
                </div>
                @else
                <span class="sv-ptz-unavailable">PTZ not available for this camera</span>
                @endif
            </div>

        </div>
    </div>

    {{-- ── Card Footer ──────────────────────────────────────── --}}
    <div class="sv-card-footer">
        <div class="sv-card-stat">
            <i class="bi bi-reception-4" style="color:var(--green)"></i>
            <span>HLS</span>
        </div>
        <div class="sv-card-stat" id="stat-quality-label-{{ $id }}">
            <i class="bi bi-layers"></i>
            <span>{{ strtoupper($currQuality) }}</span>
        </div>
        <div class="sv-card-stat">
            <i class="bi bi-hdd-network"></i>
            <span>MediaMTX → Browser</span>
        </div>
    </div>

</div>
