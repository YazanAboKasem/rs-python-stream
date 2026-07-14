<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Token — Tunnel Registration Auth
    |--------------------------------------------------------------------------
    | Used by TunnelController to authenticate requests from connect-to-server.sh
    | Set SURVEILLANCE_TOKEN in .env on both this server and the local machine.
    */
    'api_token' => env('SURVEILLANCE_TOKEN'),

    /*
     * Asset version — bump this to bust browser cache on all devices.
     * Format: MAJOR.MINOR (e.g. 3.2 after any JS/CSS change)
     */
    'asset_version' => '3.3',

    /*
    |--------------------------------------------------------------------------
    | Surveillance Stream Configuration
    |--------------------------------------------------------------------------
    |
    | HOW TO CHANGE SERVER FOR DEPLOYMENT:
    |
    | Option A — Cloudflare Tunnel (recommended for local cameras):
    |   Set MEDIA_SERVER_HLS_URL to the full tunnel HTTPS URL.
    |   Example: MEDIA_SERVER_HLS_URL=https://abc-xyz.trycloudflare.com
    |
    | Option B — Public IP / VPS with open ports:
    |   Set MEDIA_SERVER_HOST to the server IP or domain.
    |   Example: MEDIA_SERVER_HOST=stream.yourdomain.com
    |
    | The browser connects DIRECTLY to MediaMTX. Laravel never relays video.
    |
    */

    'media_server' => [
        'host'         => env('MEDIA_SERVER_HOST', '127.0.0.1'),
        'hls_port'     => env('MEDIA_SERVER_HLS_PORT',    8888),
        'webrtc_port'  => env('MEDIA_SERVER_WEBRTC_PORT', 8889),
        'rtsp_port'    => env('MEDIA_SERVER_RTSP_PORT',   8554),

        // ── Full URL overrides (Cloudflare Tunnel / reverse proxy) ──────────
        // When set, these take precedence over host+port above.
        // Use HTTPS, no trailing slash.
        // Example: MEDIA_SERVER_HLS_URL=https://abc-xyz.trycloudflare.com
        'hls_base_url'    => env('MEDIA_SERVER_HLS_URL'),    // overrides host:hls_port
        'webrtc_base_url' => env('MEDIA_SERVER_WEBRTC_URL'), // overrides host:webrtc_port
    ],

    /*
    |--------------------------------------------------------------------------
    | Camera Registry
    |--------------------------------------------------------------------------
    |
    | Add cameras here. Each entry becomes a card on the dashboard.
    | Future multi-camera support: just add more items to this array.
    |
    | Keys:
    |   id     → unique identifier (used in HTML ids and JS)
    |   label  → human-readable name shown on the card
    |   path   → MediaMTX stream path (must match mediamtx.yml paths section)
    |   enabled → set to false to hide without deleting
    |
    */

    'cameras' => [
        [
            'id'       => 'cam1',
            'label'    => 'Camera 1 — Front View',
            'path'     => 'cam1',
            'path_sub'   => 'cam1_sub',
            'path_ultra' => 'cam1_ultra',
            'path_live'  => 'cam1_live',
            'ip'       => '192.168.1.64',
            'enabled'  => true,
            'ptz'      => true,
        ],

        [
            'id'       => 'cam2',
            'label'    => 'Camera 2 — Rear PTZ',
            'path'     => 'cam2',
            'path_sub'   => 'cam2_sub',
            'path_ultra' => 'cam2_ultra',
            'path_live'  => 'cam2_live',
            'ip'       => '192.168.1.65',
            'enabled'  => true,
            'ptz'      => true,
        ],

        [
            'id'       => 'cam3',
            'label'    => 'Camera 3 — Back View',
            'path'     => 'cam3',
            'path_sub'   => 'cam3_sub',
            'path_ultra' => 'cam3_ultra',
            'path_live'  => 'cam3_live',
            'ip'       => '192.168.1.67',
            'enabled'  => true,
            'ptz'      => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Server Settings
    |--------------------------------------------------------------------------
    */
    'websocket' => [
        'host' => env('WS_HOST', '0.0.0.0'),
        'port' => env('WS_PORT', 6001),
    ],

];
