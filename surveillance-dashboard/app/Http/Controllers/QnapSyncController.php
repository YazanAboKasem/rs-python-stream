<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\JetsonWebSocketService;

class QnapSyncController extends Controller
{
    private $wsService;

    public function __construct(JetsonWebSocketService $wsService)
    {
        $this->wsService = $wsService;
    }

    /**
     * POST /api/surveillance/sync/start
     *
     * Tells a specific device to start uploading its recordings to this
     * VPS via HTTP. No QNAP credentials needed — the device uploads
     * directly to the Laravel API.
     */
    public function start(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'device_id' => 'required|string',
            'scope' => 'required|string|in:all,today,last_n_days,cameras',
            'cameras' => 'nullable|array',
            'days' => 'nullable|integer',
            'source' => 'nullable|string|in:main,sub',
            'delete_after_upload' => 'boolean',
            'overwrite_existing' => 'boolean',
            'files' => 'nullable|array',
        ]);

        $deviceId = $request->input('device_id');

        if (! $this->wsService->isOnline($deviceId)) {
            return response()->json([
                'success' => false,
                'error' => "Device {$deviceId} is offline. Cannot start sync."
            ], 400);
        }

        $requestId = 'sync_' . uniqid();

        // Build VPS upload config — the device will use this to upload files
        // Fall back to the request URL's scheme and host if app.url is localhost or empty
        $baseUrl = rtrim(config('app.url'), '/');
        if (str_contains($baseUrl, 'localhost') || empty($baseUrl)) {
            $baseUrl = $request->getSchemeAndHttpHost();
        }

        $vpsConfig = [
            'upload_url' => $baseUrl . '/api/surveillance/recordings/upload',
            'token' => config('surveillance.api_token'),
        ];

        $options = [
            'scope' => $request->input('scope'),
            'cameras' => $request->input('cameras', []),
            'days' => $request->input('days') ? (int) $request->input('days') : null,
            'source' => $request->input('source', 'sub'),
            'delete_after_upload' => $request->boolean('delete_after_upload'),
            'overwrite_existing' => $request->boolean('overwrite_existing'),
            'files' => $request->input('files', []),
        ];

        // Remember which device owns this sync request, so pause/resume/cancel
        // (which only get the request id back from the browser) can be targeted.
        Cache::put("sync_request_device_{$requestId}", $deviceId, 86400);

        // Send sync start command via WS, targeted at this device only
        $this->wsService->sendSyncStart($deviceId, $requestId, $vpsConfig, $options);

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'message' => "Sync recordings command sent to {$deviceId}.",
        ]);
    }

    /**
     * GET /api/surveillance/sync/progress/{requestId}
     */
    public function progress(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check for sync.start.ack, sync.progress, sync.complete
        $startAck = Cache::get("ws_response_sync.start.ack_{$requestId}");
        $progress = Cache::get("ws_response_sync.progress_{$requestId}");
        $complete = Cache::get("ws_response_sync.complete_{$requestId}");

        return response()->json([
            'request_id' => $requestId,
            'start_ack' => $startAck,
            'progress' => $progress,
            'complete' => $complete
        ]);
    }

    /**
     * POST /api/surveillance/sync/pause/{requestId}
     */
    public function pause(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $this->resolveSyncOwner($requestId);
        if (! $deviceId) {
            return response()->json(['error' => 'Unknown or expired sync request'], 404);
        }

        $this->wsService->sendEvent('sync.pause', $deviceId, ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Pause command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/resume/{requestId}
     */
    public function resume(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $this->resolveSyncOwner($requestId);
        if (! $deviceId) {
            return response()->json(['error' => 'Unknown or expired sync request'], 404);
        }

        $this->wsService->sendEvent('sync.resume', $deviceId, ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Resume command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/cancel/{requestId}
     */
    public function cancel(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $this->resolveSyncOwner($requestId);
        if (! $deviceId) {
            return response()->json(['error' => 'Unknown or expired sync request'], 404);
        }

        $this->wsService->sendEvent('sync.cancel', $deviceId, ['request_id' => $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'Cancel command sent.'
        ]);
    }

    /**
     * POST /api/surveillance/sync/scan
     *
     * Tells a specific device to list files ready for sync based on filters.
     */
    public function scan(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'device_id' => 'required|string',
            'scope' => 'required|string|in:all,today,last_n_days,cameras',
            'cameras' => 'nullable|array',
            'days' => 'nullable|integer',
            'source' => 'nullable|string|in:main,sub',
        ]);

        $deviceId = $request->input('device_id');

        if (! $this->wsService->isOnline($deviceId)) {
            return response()->json([
                'success' => false,
                'error' => "Device {$deviceId} is offline. Cannot scan files."
            ], 400);
        }

        $requestId = 'scan_' . uniqid();
        $options = [
            'scope' => $request->input('scope'),
            'cameras' => $request->input('cameras', []),
            'days' => $request->input('days') ? (int) $request->input('days') : null,
            'source' => $request->input('source', 'sub'),
        ];

        $this->wsService->sendSyncListFiles($deviceId, $requestId, $options);

        // Scanning can take much longer than a single HTTP request should
        // block for (ffprobe-ing thousands of recording files) — return
        // immediately and let the browser poll /sync/scan/status instead.
        return response()->json([
            'success' => true,
            'request_id' => $requestId,
        ]);
    }

    /**
     * GET /api/surveillance/sync/scan/status/{requestId}
     *
     * Polled by the browser while a scan (started via /sync/scan) is running.
     */
    public function scanStatus(Request $request, string $requestId): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $response = Cache::get("ws_response_sync.list_files.ack_{$requestId}");

        if ($response === null) {
            return response()->json(['done' => false]);
        }

        if (($response['status'] ?? 'success') === 'error') {
            return response()->json([
                'done' => true,
                'success' => false,
                'error' => $response['error'] ?? 'Scan failed on device.',
            ]);
        }

        return response()->json([
            'done' => true,
            'success' => true,
            'files' => $response['files'] ?? [],
        ]);
    }

    /**
     * Look up which device owns a given sync request id.
     */
    private function resolveSyncOwner(string $requestId): ?string
    {
        return Cache::get("sync_request_device_{$requestId}");
    }

    /**
     * Helper to validate token
     */
    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}
