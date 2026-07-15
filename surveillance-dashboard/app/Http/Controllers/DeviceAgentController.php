<?php

namespace App\Http\Controllers;

use App\Models\DeviceAgent;
use App\Models\DeviceAgentCommand;
use App\Models\DeviceTerminalSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceAgentController extends Controller
{
    // ─── Agent API Endpoints (called by Python agent) ──────────────────────────

    /**
     * POST /api/device-agent/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'jetson_id' => 'required|string',
            'hostname' => 'nullable|string',
            'agent_version' => 'nullable|string',
            'online' => 'required|boolean',
            'uptime' => 'integer',
            'cpu' => 'integer',
            'ram' => 'integer',
            'disk' => 'integer',
            'temperature' => 'integer',
        ]);

        $agent = DeviceAgent::updateOrCreate(
            ['jetson_id' => $request->input('jetson_id')],
            [
                'hostname' => $request->input('hostname'),
                'agent_version' => $request->input('agent_version', '1.0'),
                'online' => true,
                'last_seen' => now(),
                'uptime' => $request->input('uptime', 0),
                'cpu' => $request->input('cpu', 0),
                'ram' => $request->input('ram', 0),
                'disk' => $request->input('disk', 0),
                'temperature' => $request->input('temperature', 0),
                'system_info' => $request->except(['jetson_id', 'hostname', 'agent_version']),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat updated successfully.',
            'agent' => $agent
        ]);
    }

    /**
     * GET /api/device-agent/pending-commands
     */
    public function pendingCommands(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jetsonId = $request->query('jetson_id');
        if (empty($jetsonId)) {
            return response()->json(['error' => 'jetson_id is required'], 422);
        }

        $commands = DeviceAgentCommand::where('jetson_id', $jetsonId)
            ->where('status', 'pending')
            ->get();

        return response()->json(['commands' => $commands]);
    }

    /**
     * POST /api/device-agent/command-result
     */
    public function commandResult(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'command_id' => 'required|integer',
            'status' => 'required|string|in:executing,completed,failed',
            'result' => 'nullable|string',
        ]);

        $command = DeviceAgentCommand::find($request->input('command_id'));
        if (!$command) {
            return response()->json(['error' => 'Command not found'], 404);
        }

        $updateData = [
            'status' => $request->input('status'),
            'result' => $request->input('result'),
        ];

        if ($request->input('status') === 'executing' && is_null($command->executed_at)) {
            $updateData['executed_at'] = now();
        }

        $command->update($updateData);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/device-agent/terminal-ready
     */
    public function terminalReady(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'session_id' => 'required|integer',
            'port' => 'required|integer',
        ]);

        $session = DeviceTerminalSession::find($request->input('session_id'));
        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $host = request()->getHost();
        $connectionString = "ssh device-agent@{$host} -p {$session->port}";

        $session->update([
            'status' => 'open',
            'connection_string' => $connectionString,
            'opened_at' => now(),
            'expires_at' => now()->addMinutes($session->timeout_minutes),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/device-agent/terminal-closed
     */
    public function terminalClosed(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'session_id' => 'required|integer',
            'reason' => 'nullable|string',
        ]);

        $session = DeviceTerminalSession::find($request->input('session_id'));
        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }


    // ─── Dashboard Action Endpoints (called by browser UI) ─────────────────────

    /**
     * POST /surveillance/devices/{deviceId}/terminal/request
     */
    public function requestTerminal(string $deviceId): JsonResponse
    {
        // 1. Close any expired/abandoned sessions first
        DeviceTerminalSession::where('jetson_id', $deviceId)
            ->whereIn('status', ['requested', 'open'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'closed_at' => now()]);

        // 2. Determine a dynamic port range 22000-22999 that is not occupied
        $usedPorts = DeviceTerminalSession::whereIn('status', ['requested', 'open'])
            ->pluck('port')
            ->toArray();

        $port = 22000;
        while (in_array($port, $usedPorts)) {
            $port++;
        }

        if ($port > 22999) {
            return response()->json(['error' => 'All remote access ports are currently occupied. Please try again later.'], 503);
        }

        // 3. Create active session entry
        $session = DeviceTerminalSession::create([
            'jetson_id' => $deviceId,
            'port' => $port,
            'status' => 'requested',
            'timeout_minutes' => 10,
            'expires_at' => now()->addMinutes(10),
        ]);

        // 4. Create the command for python agent
        $command = DeviceAgentCommand::create([
            'jetson_id' => $deviceId,
            'command' => 'open_terminal',
            'payload' => [
                'session_id' => $session->id,
                'port' => $port,
                'timeout_minutes' => 10,
            ],
            'status' => 'pending',
        ]);

        $session->update(['command_id' => $command->id]);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'port' => $port,
            'status' => $session->status,
        ]);
    }

    /**
     * GET /surveillance/devices/{deviceId}/terminal/status
     */
    public function terminalStatus(string $deviceId): JsonResponse
    {
        $session = DeviceTerminalSession::where('jetson_id', $deviceId)
            ->orderBy('id', 'desc')
            ->first();

        if (!$session) {
            return response()->json(['status' => 'none']);
        }

        // Handle auto-expiration calculation
        if (in_array($session->status, ['requested', 'open']) && $session->expires_at && $session->expires_at->isPast()) {
            $session->update(['status' => 'expired', 'closed_at' => now()]);
        }

        $remainingSeconds = 0;
        if ($session->status === 'open' && $session->expires_at) {
            $remainingSeconds = max(0, $session->expires_at->diffInSeconds(now()));
        }

        return response()->json([
            'status' => $session->status,
            'port' => $session->port,
            'connection_string' => $session->connection_string,
            'remaining_seconds' => $remainingSeconds,
            'opened_at' => $session->opened_at ? $session->opened_at->toIso8601String() : null,
        ]);
    }


    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function isAuthorized(Request $request): bool
    {
        $token = config('surveillance.api_token');
        if (empty($token)) {
            return false;
        }
        return $request->header('Authorization', '') === "Bearer {$token}";
    }
}
