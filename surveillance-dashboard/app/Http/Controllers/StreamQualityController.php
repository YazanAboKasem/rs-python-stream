<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * RoadShield — Stream Quality Controller
 *
 * Stores per-camera quality settings in Laravel cache.
 * camera-control.py polls /quality/settings and restarts
 * FFmpeg at the requested resolution / FPS / bitrate.
 *
 * Quality presets (sent from browser):
 *   hd      1920×1080  25 fps  4000 Kbps
 *   sd      1280×720   15 fps  1000 Kbps
 *   low     854×480    10 fps   400 Kbps
 *   ultra   640×360     5 fps   150 Kbps
 *   min     426×240     1 fps    50 Kbps
 */
class StreamQualityController extends Controller
{
    /** Quality presets — browser sends preset name, Python applies it */
    const PRESETS = [
        'hd'    => ['width' => 1920, 'height' => 1080, 'fps' => 25, 'bitrate' => 4000],
        'sd'    => ['width' => 1280, 'height' =>  720, 'fps' => 15, 'bitrate' => 1000],
        'low'   => ['width' =>  854, 'height' =>  480, 'fps' => 10, 'bitrate' =>  400],
        'ultra' => ['width' =>  640, 'height' =>  360, 'fps' =>  5, 'bitrate' =>  150],
        'min'   => ['width' =>  426, 'height' =>  240, 'fps' =>  1, 'bitrate' =>   50],
    ];

    const CACHE_TTL = 86400; // 24h

    // ─── POST /api/surveillance/cameras/{id}/quality ──────────────────────────
    /**
     * Browser → set quality.
     * Accepts either a named preset OR explicit width/height/fps/bitrate.
     */
    public function setQuality(Request $request, string $cameraId)
    {
        $preset = $request->input('preset');

        if ($preset && isset(self::PRESETS[$preset])) {
            $settings = array_merge(self::PRESETS[$preset], ['preset' => $preset]);
        } else {
            $settings = [
                'preset'  => 'custom',
                'width'   => (int) $request->input('width',   1280),
                'height'  => (int) $request->input('height',   720),
                'fps'     => (int) $request->input('fps',       15),
                'bitrate' => (int) $request->input('bitrate', 1000),
            ];
        }

        $settings['quality']    = $settings['preset'];
        $settings['updated_at'] = now()->toISOString();
        $settings['camera_id']  = $cameraId;

        // Write to both keys to keep settings unified
        Cache::put("stream_quality_{$cameraId}",  $settings, self::CACHE_TTL);
        Cache::put("camera_settings_{$cameraId}", $settings, self::CACHE_TTL);

        \Log::info("[StreamQuality] {$cameraId}: preset={$settings['preset']} "
            . "{$settings['width']}x{$settings['height']} "
            . "@{$settings['fps']}fps @{$settings['bitrate']}Kbps");

        // Send via WebSocket instantly
        try {
            $camerasConfig = config('surveillance.cameras', []);
            $allSettings = [];
            foreach ($camerasConfig as $cam) {
                if (! ($cam['enabled'] ?? false)) {
                    continue;
                }
                $id = $cam['id'];
                $camSettings = ($id === $cameraId) ? $settings : Cache::get("camera_settings_{$id}");
                $allSettings[$id] = [
                    'quality' => $camSettings['quality'] ?? 'hd',
                    'fps'     => (int) ($camSettings['fps'] ?? 15),
                ];
            }

            $wsService = app(\App\Services\JetsonWebSocketService::class);
            $wsService->sendSettingsUpdate($allSettings);
            \Log::info('[StreamQuality] Settings update pushed via WebSocket', $allSettings);
        } catch (\Exception $e) {
            \Log::error('[StreamQuality] Failed to push settings via WebSocket: ' . $e->getMessage());
        }

        return response()->json([
            'success'  => true,
            'camera'   => $cameraId,
            'settings' => $settings,
        ]);
    }

    // ─── GET /api/surveillance/cameras/{id}/quality/settings ─────────────────
    /**
     * Python polls this to detect quality changes.
     * Returns current settings for the camera.
     */
    public function getSettings(string $cameraId)
    {
        $settings = Cache::get("stream_quality_{$cameraId}", [
            'preset'     => 'sd',
            'camera_id'  => $cameraId,
            'width'      => 1280,
            'height'     =>  720,
            'fps'        =>  15,
            'bitrate'    => 1000,
            'updated_at' => null,
        ]);

        return response()->json([
            'camera_id' => $cameraId,
            'settings'  => $settings,
        ]);
    }

    // ─── GET /api/surveillance/quality/presets ────────────────────────────────
    /**
     * Returns all available presets (used by JS to populate buttons).
     */
    public function presets()
    {
        return response()->json([
            'presets' => self::PRESETS,
        ]);
    }
}
