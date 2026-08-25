<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\JetsonWebSocketService;

class JetsonStatusController extends Controller
{
    private $wsService;

    public function __construct(JetsonWebSocketService $wsService)
    {
        $this->wsService = $wsService;
    }

    /**
     * GET /api/surveillance/jetson/status?device_id=rock1
     */
    public function status(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $request->query('device_id');
        if (empty($deviceId)) {
            return response()->json(['error' => 'device_id is required'], 422);
        }

        return response()->json(
            $this->wsService->getConnectionInfo($deviceId)
        );
    }

    /**
     * POST /api/surveillance/jetson/reboot
     * Body: { "device_id": "rock1" }
     */
    public function reboot(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $deviceId = $request->input('device_id');
        if (empty($deviceId)) {
            return response()->json(['error' => 'device_id is required'], 422);
        }

        if (! $this->wsService->isOnline($deviceId)) {
            return response()->json([
                'success' => false,
                'error' => 'Device is offline. Cannot send reboot command.'
            ], 400);
        }

        $this->wsService->sendReboot($deviceId);

        return response()->json([
            'success' => true,
            'message' => "Reboot command sent to {$deviceId}."
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) return false;
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}
