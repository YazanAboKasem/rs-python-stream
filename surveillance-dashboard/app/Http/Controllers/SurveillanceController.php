<?php

namespace App\Http\Controllers;

use App\Http\Controllers\TunnelController;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SurveillanceController extends Controller
{
    /**
     * Display the live surveillance dashboard.
     *
     * Laravel's ONLY job here:
     *   1. Read camera config
     *   2. Build stream URLs (browser will connect to these directly)
     *   3. Pass data to the view
     *
     * Laravel does NOT proxy, relay, or process any video.
     */
    public function index(): View
    {
        $server = config('surveillance.media_server');

        // ── Resolve HLS base URL ─────────────────────────────────────────────
        //   4. Force local fallback if '?local' query parameter is present in URL
        $cachedUrl  = request()->has('local') ? null : Cache::get(TunnelController::CACHE_KEY);

        $hlsBase    = self::resolveBaseUrl(
            fullUrl: $cachedUrl ?? $server['hls_base_url'] ?? null,
            host:    $server['host'],
            port:    $server['hls_port'],
        );
        $webrtcBase = self::resolveBaseUrl(
            fullUrl: $cachedUrl ?? $server['webrtc_base_url'] ?? null,
            host:    $server['host'],
            port:    $server['webrtc_port'],
        );

        // Prepare camera list with resolved stream URLs
        $cameras = collect(config('surveillance.cameras'))
            ->filter(fn($cam) => $cam['enabled'])
            ->map(function ($cam) use ($hlsBase, $webrtcBase) {
                $pathHd    = $cam['path'];
                $pathSd    = $cam['path_sub']   ?? $cam['path'];
                $pathUltra = $cam['path_ultra'] ?? $cam['path_sub'] ?? $cam['path'];
                $pathLive  = $cam['path_live']  ?? "{$pathHd}_live";

                // Get cached settings or default
                $settings = Cache::get("camera_settings_{$cam['id']}", [
                    'quality' => 'hd',
                    'fps'     => 15,
                ]);

                return array_merge($cam, [
                    'hls_url'       => "{$hlsBase}/{$pathLive}/index.m3u8",
                    'webrtc_url'    => "{$webrtcBase}/{$pathHd}",
                    'hls_url_hd'    => "{$hlsBase}/{$pathHd}/index.m3u8",
                    'hls_url_sd'    => "{$hlsBase}/{$pathSd}/index.m3u8",
                    'hls_url_ultra' => "{$hlsBase}/{$pathUltra}/index.m3u8",
                    'hls_url_live'  => "{$hlsBase}/{$pathLive}/index.m3u8",
                    'current_quality' => $settings['quality'],
                    'current_fps'     => $settings['fps'],
                ]);
            })
            ->values();

        return view('surveillance.index', compact('cameras'));
    }

    /**
     * Resolve the correct base URL for a MediaMTX endpoint.
     *
     * Handles three cases automatically:
     *   1. MEDIA_SERVER_HLS_URL set → use it directly (Cloudflare Tunnel / reverse proxy)
     *   2. MEDIA_SERVER_HOST contains a scheme (https://...) → use as base, ignore port
     *   3. MEDIA_SERVER_HOST is a plain hostname/IP → build http://host:port
     */
    private static function resolveBaseUrl(?string $fullUrl, string $host, int $port): string
    {
        // Case 1: explicit full URL override (e.g. MEDIA_SERVER_HLS_URL=https://xyz.trycloudflare.com)
        if (!empty($fullUrl)) {
            return rtrim($fullUrl, '/');
        }

        // Case 2: host accidentally contains a scheme (e.g. MEDIA_SERVER_HOST=https://xyz...)
        // Strip it and ignore the port — the scheme implies a standard port (80/443).
        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            return rtrim($host, '/');
        }

        // Case 3: plain hostname or IP — build http://host:port
        return "http://{$host}:{$port}";
    }

    /**
     * Future: Health check endpoint for MediaMTX status.
     * Phase 2 will poll the MediaMTX API and return camera statuses.
     */
    // public function status(): JsonResponse { ... }

    /**
     * Future: Receive AI alert events from Python stream service.
     * Phase 3 will accept POST events (alert type, snapshot, metadata).
     */
    // public function receiveEvent(Request $request): JsonResponse { ... }
}
