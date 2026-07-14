/**
 * RoadShield Surveillance — Stream Player v3.2
 *
 * Features:
 *   1. HLS.js / Native HLS with patient retry
 *   2. Live stats (bitrate, FPS, buffer, resolution)
 *   3. Quality switching HD / SD / Ultra
 *   4. FPS limiter (canvas-based)
 *   5. Digital zoom (CSS transform)
 *   6. PTZ command queue (Laravel API)
 *
 * Cache-bust: this file is loaded with ?v= param — no manual refresh needed.
 */
(function () {
    'use strict';

    const VERSION = '3.2';

    // ─── Config ──────────────────────────────────────────────────────────────
    const CFG = {
        retryDelay:         4000,
        notReadyDelay:      3000,
        maxNotReadyRetries: 40,
        statsInterval:      1500,
        maxBitrateKbps:     8000,
        ptzApiBase:         '/api/surveillance/cameras',
        ptzSpeed:           3,
        hls: {
            lowLatencyMode:              false,
            backBufferLength:            10,
            maxBufferLength:             20,
            liveSyncDurationCount:       3,
            liveMaxLatencyDurationCount: 6,
            enableWorker:                true,
            manifestLoadingTimeOut:      5000,
            manifestLoadingMaxRetry:     0,
        },
    };

    console.log(`[StreamPlayer] v${VERSION} loaded`);

    // ─── Boot ────────────────────────────────────────────────────────────────
    function boot() {
        document.querySelectorAll('video[data-camera-id]').forEach(initPlayer);
        initQualityButtons();
        initFPSButtons();
        initPTZButtons();
    }

    // ─── Init single player ───────────────────────────────────────────────────
    function initPlayer(video) {
        const id   = video.dataset.cameraId;
        const url  = video.dataset.hlsUrl;
        const card = document.getElementById(`card-${id}`);

        if (!url || !id) {
            console.warn('[StreamPlayer] Missing data-camera-id or data-hls-url');
            return;
        }

        // Initialize state from server-rendered attributes
        video.dataset.currentQuality = video.dataset.currentQuality || 'hd';
        video.dataset.currentFps     = video.dataset.currentFps || '15';

        // Mixed-content guard
        if (location.protocol === 'https:' && url.startsWith('http:')) {
            console.error(`[StreamPlayer] Mixed content: ${url}`);
            showOverlay(card, '⚠️ Mixed Content — check .env MEDIA_SERVER_HOST', 'error');
            return;
        }

        console.log(`[StreamPlayer] Init cam: ${id} → ${url}`);
        startHLS(video, url, card, id, 0, 0);
    }

    // ─── HLS playback ─────────────────────────────────────────────────────────
    function startHLS(video, url, card, id, retry, notReady) {
        // Destroy previous HLS instance
        if (video._hls) { video._hls.destroy(); video._hls = null; }
        // Stop stats
        if (video._statsTimer) { clearInterval(video._statsTimer); video._statsTimer = null; }

        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
            const hls  = new Hls(CFG.hls);
            video._hls = hls;

            hls.loadSource(url);
            hls.attachMedia(video);

            hls.on(Hls.Events.MEDIA_ATTACHED, () => {
                showOverlay(card, 'Connecting…');
                setLive(card, false);
            });

            hls.on(Hls.Events.MANIFEST_PARSED, () => {
                hideOverlay(card);
                setLive(card, true);
                startTimestamp(card, id);
                startStats(hls, video, card, id);
                video.play().catch(() => { video.muted = true; video.play().catch(() => {}); });
            });

            hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => {
                const lv = hls.levels[data.level];
                if (lv) setResStat(card, id, lv.width, lv.height);
            });

            hls.on(Hls.Events.ERROR, (_, data) => {
                const isNotReady = data.details === 'manifestLoadError' ||
                                   data.details === 'manifestLoadTimeout';
                if (isNotReady) {
                    const attempt = notReady + 1;
                    hls.destroy(); video._hls = null;
                    if (attempt <= CFG.maxNotReadyRetries) {
                        showOverlay(card, `Waiting for stream… (${attempt})`);
                        setTimeout(() => startHLS(video, url, card, id, retry, attempt), CFG.notReadyDelay);
                    } else {
                        showOverlay(card, 'Stream unavailable — is MediaMTX running?', 'error');
                    }
                    return;
                }
                if (data.fatal) {
                    console.warn(`[StreamPlayer] ${id} fatal: ${data.type}/${data.details}`);
                    setLive(card, false);
                    hls.destroy(); video._hls = null;
                    showOverlay(card, `Reconnecting… (${retry + 1})`);
                    setTimeout(() => startHLS(video, url, card, id, retry + 1, 0), CFG.retryDelay);
                }
            });

        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            // Native HLS (Safari / iOS)
            video.src = url;
            showOverlay(card, 'Connecting…');

            const onMeta = () => {
                hideOverlay(card);
                setLive(card, true);
                startTimestamp(card, id);
                startNativeStats(video, card, id);
                video.play().catch(() => {});
            };
            const onErr = () => {
                const code = video.error?.code ?? 0;
                if (code === 4 && notReady < CFG.maxNotReadyRetries) {
                    showOverlay(card, `Waiting… (${notReady + 1})`);
                    setTimeout(() => { video.src = ''; startHLS(video, url, card, id, retry, notReady + 1); }, CFG.notReadyDelay);
                } else {
                    setLive(card, false);
                    setTimeout(() => { video.src = ''; startHLS(video, url, card, id, retry + 1, 0); }, CFG.retryDelay);
                }
            };

            video.addEventListener('loadedmetadata', onMeta, { once: true });
            video.addEventListener('error', onErr, { once: true });
        } else {
            showOverlay(card, '❌ Browser does not support HLS', 'error');
        }
    }

    // ─── Stats ────────────────────────────────────────────────────────────────
    function startStats(hls, video, card, id) {
        if (video._statsTimer) clearInterval(video._statsTimer);
        video._statsTimer = setInterval(() => {
            // Bitrate
            const bw = hls.bandwidthEstimate ? Math.round(hls.bandwidthEstimate / 1000) : 0;
            setBwStat(card, id, bw);

            // Buffer
            const buf = hls.mainForwardBufferInfo;
            if (buf) setStatVal(card, `stat-buffer-${id}`, `${buf.len.toFixed(1)}s`,
                buf.len > 2 ? 'good' : buf.len > 0.5 ? 'warn' : 'bad');

            // Level info
            const lv = hls.levels?.[hls.currentLevel];
            if (lv) {
                setResStat(card, id, lv.width, lv.height);
                const fr = lv.attrs?.['FRAME-RATE'];
                if (fr) setStatVal(card, `stat-fps-${id}`, Math.round(fr), fr >= 20 ? 'good' : 'warn');
            }

            // FPS from playback quality
            if (video.getVideoPlaybackQuality) {
                const q = video.getVideoPlaybackQuality();
                if (q._prev != null) {
                    const fps = Math.round((q.totalVideoFrames - q._prev) / (CFG.statsInterval / 1000));
                    if (fps > 0 && fps < 120)
                        setStatVal(card, `stat-fps-${id}`, fps, fps >= 20 ? 'good' : fps >= 10 ? 'warn' : 'bad');
                }
                q._prev = q.totalVideoFrames;
            }
        }, CFG.statsInterval);
    }

    function startNativeStats(video, card, id) {
        if (video._statsTimer) clearInterval(video._statsTimer);
        video._statsTimer = setInterval(() => {
            if (video.videoWidth) setResStat(card, id, video.videoWidth, video.videoHeight);
            if (video.getVideoPlaybackQuality) {
                const q   = video.getVideoPlaybackQuality();
                const fps = Math.round((q.totalVideoFrames - (q._prev ?? q.totalVideoFrames)) / (CFG.statsInterval / 1000));
                q._prev   = q.totalVideoFrames;
                if (fps > 0) setStatVal(card, `stat-fps-${id}`, fps, fps >= 20 ? 'good' : 'warn');
            }
        }, CFG.statsInterval);
    }

    function setBwStat(card, id, kbps) {
        let label, cls;
        if (!kbps) { label = '–'; cls = 'muted'; }
        else if (kbps >= 1000) { label = `${(kbps/1000).toFixed(1)} Mbps`; cls = kbps > 2000 ? 'good' : 'warn'; }
        else { label = `${kbps} Kbps`; cls = kbps < 300 ? 'bad' : 'warn'; }

        setStatVal(card, `stat-bitrate-${id}`, label, cls);

        const bar  = card?.querySelector(`#stat-bitrate-bar-${id}`);
        if (bar) {
            bar.style.width      = `${Math.min(100, (kbps / CFG.maxBitrateKbps) * 100)}%`;
            bar.style.background = cls === 'bad' ? 'var(--red)' : cls === 'warn' ? 'var(--amber)' : 'var(--green)';
        }
    }

    function setResStat(card, id, w, h) {
        if (w) setStatVal(card, `stat-res-${id}`, `${w}×${h}`, '');
    }

    function setStatVal(card, elId, val, cls) {
        const el = card?.querySelector(`#${elId}`);
        if (!el) return;
        el.textContent = val;
        if (cls !== undefined) el.className = `sv-stat-value${cls ? ' ' + cls : ''}`;
    }

    // ─── Quality & FPS Dynamic Settings ──────────────────────────────────────
    function initQualityButtons() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.sv-quality-btn');
            if (!btn) return;
            const camId   = btn.dataset.camera;
            const quality = btn.dataset.quality;
            const video   = document.getElementById(`player-${camId}`);
            if (camId && quality && video) {
                sendSettings(camId, quality, parseInt(video.dataset.currentFps, 10));
            }
        });
    }

    function initFPSButtons() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.sv-fps-btn');
            if (!btn) return;
            const camId = btn.dataset.camera;
            const fps   = parseInt(btn.dataset.fps, 10);
            const video = document.getElementById(`player-${camId}`);
            if (camId && !isNaN(fps) && video) {
                sendSettings(camId, video.dataset.currentQuality, fps);
            }
        });
    }

    function sendSettings(camId, quality, fps) {
        const video = document.getElementById(`player-${camId}`);
        const card  = document.getElementById(`card-${camId}`);
        if (!video || !card) return;

        // Update state in dataset
        video.dataset.currentQuality = quality;
        video.dataset.currentFps     = fps;

        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf  = document.querySelector('meta[name="csrf-token"]')?.content || '';

        console.log(`[StreamPlayer] Setting ${camId} Quality: ${quality.toUpperCase()}, FPS: ${fps}`);

        // Update active class on Quality buttons
        const qContainer = document.getElementById(`quality-btns-${camId}`);
        qContainer?.querySelectorAll('.sv-quality-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.quality === quality);
            b.classList.add('switching');
        });
        setTimeout(() => {
            qContainer?.querySelectorAll('.sv-quality-btn.switching').forEach(b => b.classList.remove('switching'));
        }, 4000);

        // Update active class on FPS buttons
        const fpsContainer = document.getElementById(`fps-btns-${camId}`);
        fpsContainer?.querySelectorAll('.sv-fps-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.fps, 10) === fps);
        });

        // Update footer label
        const lbl = card.querySelector(`#stat-quality-label-${camId} span`);
        if (lbl) lbl.textContent = quality.toUpperCase();

        // Show loading overlay
        showOverlay(card, `Adjusting stream parameters…`);
        setLive(card, false);

        // Reset stats
        ['stat-bitrate', 'stat-fps', 'stat-buffer', 'stat-res'].forEach(k => {
            setStatVal(card, `${k}-${camId}`, '–', 'muted');
        });

        // Send settings to Laravel API
        fetch(`/api/surveillance/cameras/${camId}/settings`, {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN':  csrf,
            },
            body: JSON.stringify({ quality, fps }),
        })
        .then(r => {
            if (!r.ok) throw new Error(`HTTP error ${r.status}`);
            return r.json();
        })
        .then(data => {
            if (data.success) {
                console.log(`[StreamPlayer] Server received settings for ${camId}. Reloading stream in 6s...`);
                
                let remaining = 6;
                showOverlay(card, `Restarting transcoder… (${remaining}s)`);
                const countdown = setInterval(() => {
                    remaining--;
                    if (remaining > 0) {
                        showOverlay(card, `Restarting transcoder… (${remaining}s)`);
                    } else {
                        clearInterval(countdown);
                    }
                }, 1000);

                // Wait for local FFmpeg to restart and feed MediaMTX
                setTimeout(() => {
                    clearInterval(countdown);
                    const url = video.dataset.hlsUrlLive || video.dataset.hlsUrl;
                    const urlWithBuster = url.includes('?') ? `${url}&t=${Date.now()}` : `${url}?t=${Date.now()}`;
                    startHLS(video, urlWithBuster, card, camId, 0, 0);
                }, 6000);
            } else {
                console.warn('[StreamPlayer] Failed to apply settings:', data);
                showOverlay(card, '⚠️ Settings error — check token', 'error');
            }
        })
        .catch(err => {
            console.error('[StreamPlayer] Network error:', err);
            showOverlay(card, '⚠️ Connection error', 'error');
        });
    }

    // ─── Digital Zoom ─────────────────────────────────────────────────────────
    window.applyZoom = function (camId, level) {
        level = Math.max(1, Math.min(3, parseFloat(level)));
        const video  = document.getElementById(`player-${camId}`);
        const label  = document.getElementById(`zoom-label-${camId}`);
        const slider = document.getElementById(`zoom-slider-${camId}`);
        if (video)  { video.style.transform = `scale(${level})`; video.style.transformOrigin = 'center'; }
        if (label)  label.textContent  = `${level.toFixed(1)}×`;
        if (slider) slider.value       = level;
    };

    window.adjustZoom = function (camId, delta) {
        const slider = document.getElementById(`zoom-slider-${camId}`);
        if (slider) applyZoom(camId, parseFloat(slider.value) + delta);
    };

    // ─── Controls panel toggle ────────────────────────────────────────────────
    window.toggleControls = function (camId) {
        const panel  = document.getElementById(`ctrl-panel-${camId}`);
        const toggle = document.getElementById(`ctrl-toggle-${camId}`);
        panel?.classList.toggle('open');
        toggle?.classList.toggle('active', panel?.classList.contains('open'));
    };

    // ─── PTZ ──────────────────────────────────────────────────────────────────
    function initPTZButtons() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.sv-ptz-btn[data-ptz]');
            if (!btn) return;
            sendPTZ(btn.dataset.cam, btn.dataset.ptz);
        });
    }

    function sendPTZ(camId, action) {
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';
        const csrf  = document.querySelector('meta[name="csrf-token"]')?.content || '';

        fetch(`${CFG.ptzApiBase}/${camId}/ptz`, {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Authorization': `Bearer ${token}`,
                'X-CSRF-TOKEN':  csrf,
            },
            body: JSON.stringify({ action, speed: CFG.ptzSpeed }),
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) flashBtn(camId, action);
            else console.warn('[PTZ] Error:', d);
        })
        .catch(err => console.error('[PTZ] Network:', err));
    }

    function flashBtn(camId, action) {
        const btn = document.querySelector(`[data-ptz="${action}"][data-cam="${camId}"]`);
        if (!btn) return;
        const prev = btn.style.cssText;
        btn.style.cssText += ';background:var(--accent);color:#fff';
        setTimeout(() => btn.style.cssText = prev, 300);
    }

    // ─── UI Helpers ───────────────────────────────────────────────────────────
    function showOverlay(card, msg, type) {
        if (!card) return;
        const ov = card.querySelector('.sv-video-overlay');
        const m  = card.querySelector('.sv-overlay-msg');
        ov?.classList.remove('hidden');
        if (m) { m.textContent = msg; m.style.color = type === 'error' ? 'var(--red)' : ''; }
    }
    function hideOverlay(card) {
        card?.querySelector('.sv-video-overlay')?.classList.add('hidden');
    }
    function setLive(card, live) {
        if (!card) return;
        const b = card.querySelector('.sv-live-badge');
        if (b) b.style.opacity = live ? '1' : '0.3';
    }
    function startTimestamp(card, id) {
        if (!card || card._ticking) return;
        card._ticking = true;
        const el = card.querySelector(`#ts-${id}`);
        if (!el) return;
        const tick = () => {
            const n = new Date();
            el.textContent = n.toLocaleTimeString('en-US', { hour12: false }) +
                ' · ' + n.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
        };
        tick(); setInterval(tick, 1000);
    }

    // ─── Start ────────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

})();
