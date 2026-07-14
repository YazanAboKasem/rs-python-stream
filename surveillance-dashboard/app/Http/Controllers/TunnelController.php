<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * TunnelController — Receives Cloudflare Tunnel URL from connect-to-server.sh
 *
 * When connect-to-server.sh gets a new tunnel URL, it POSTs it here.
 * SurveillanceController reads it from cache automatically.
 * No .env editing, no config:cache needed.
 */
class TunnelController extends Controller
{
    /** Cache key used by both this controller and SurveillanceController */
    const CACHE_KEY = 'surveillance_tunnel_hls_url';

    /** Cache TTL — 24 hours. Tunnel re-registers on each start. */
    const CACHE_TTL = 60 * 60 * 24;

    /**
     * POST /api/surveillance/register-tunnel
     *
     * Called by connect-to-server.sh with the new Cloudflare tunnel URL.
     *
     * Body: { "hls_url": "https://xyz.trycloudflare.com" }
     * Header: Authorization: Bearer {SURVEILLANCE_TOKEN}
     */
    public function register(Request $request): JsonResponse
    {
        // ── Auth ──────────────────────────────────────────────────────────────
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // ── Validate ──────────────────────────────────────────────────────────
        $url = trim($request->input('hls_url', ''));

        if (empty($url)) {
            return response()->json(['error' => 'hls_url is required'], 422);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'hls_url must be a valid URL'], 422);
        }

        // ── Store in cache ────────────────────────────────────────────────────
        $url = rtrim($url, '/');
        Cache::put(self::CACHE_KEY, $url, self::CACHE_TTL);

        \Log::info('[Surveillance] Tunnel URL registered', ['url' => $url]);

        return response()->json([
            'success'    => true,
            'hls_url'    => $url,
            'expires_in' => self::CACHE_TTL,
            'message'    => 'Tunnel URL registered. Dashboard will use it immediately.',
        ]);
    }

    /**
     * DELETE /api/surveillance/register-tunnel
     *
     * Clears the tunnel URL (e.g. when connect-to-server.sh stops).
     * Dashboard falls back to .env MEDIA_SERVER_HLS_URL or host:port.
     */
    public function clear(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Cache::forget(self::CACHE_KEY);
        \Log::info('[Surveillance] Tunnel URL cleared');

        return response()->json(['success' => true, 'message' => 'Tunnel URL cleared.']);
    }

    /**
     * GET /api/surveillance/tunnel-status
     *
     * Returns current registered tunnel URL and registration status.
     * Useful for debugging from browser or curl.
     */
    public function status(): JsonResponse
    {
        $url = Cache::get(self::CACHE_KEY);

        return response()->json([
            'registered' => ! empty($url),
            'hls_url'    => $url,
            'cam1_stream' => $url ? "{$url}/cam1/index.m3u8" : null,
            'cam2_stream' => $url ? "{$url}/cam2/index.m3u8" : null,
            'cam3_stream' => $url ? "{$url}/cam3/index.m3u8" : null,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');

        // If no token configured — reject all (don't leave open)
        if (empty($token)) {
            return false;
        }

        $header = $request->header('Authorization', '');
        $authorized = $header === "Bearer {$token}";
        if ($authorized) {
            app(\App\Services\JetsonWebSocketService::class)->markOnline($request);
        }
        return $authorized;
    }
}
