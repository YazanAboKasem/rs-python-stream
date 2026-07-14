<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\WebSocket\JetsonWebSocketHandler;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebSocketServeCommand extends Command
{
    protected $signature = 'websocket:serve {--port=6001} {--host=0.0.0.0}';
    protected $description = 'Start the RoadShield surveillance WebSocket server';

    public function handle()
    {
        $port = $this->option('port');
        $host = $this->option('host');

        $this->info("Starting WebSocket server on ws://{$host}:{$port}...");

        $handler = new JetsonWebSocketHandler();
        
        // Reset online status on start
        Cache::put('jetson_ws_online', false, 86400);
        Cache::put('ws_outbound_queue', [], 86400);

        try {
            $socket = new SocketServer("{$host}:{$port}");
        } catch (\Exception $e) {
            $this->error("Failed to start socket server: " . $e->getMessage());
            return 1;
        }

        $socket->on('connection', function (ConnectionInterface $connection) use ($handler) {
            $connId = spl_object_hash($connection);
            Log::info("[WebSocket] New connection request: {$connId}");

            $connectionState = [
                'handshake' => false,
                'buffer' => '',
                'closed' => false,
                'stream' => $connection
            ];

            $connection->on('data', function ($data) use ($connId, $handler, $connection, &$connectionState) {
                try {
                    $response = $handler->handleData($connId, $data, $connectionState);
                    if ($response !== null) {
                        $connection->write($response);
                    }
                    if (!empty($connectionState['closed'])) {
                        $connection->close();
                    }
                } catch (\Exception $e) {
                    Log::error("[WebSocket] Error handling data on {$connId}: " . $e->getMessage());
                    $connection->close();
                }
            });

            $connection->on('close', function () use ($connId, $handler) {
                $handler->handleDisconnect($connId);
            });
        });

        // Periodic timer for outbound messages queue (every 100ms)
        Loop::addPeriodicTimer(0.1, function () use ($handler) {
            try {
                $queue = Cache::get('ws_outbound_queue', []);
                if (!empty($queue)) {
                    // Clear queue immediately to avoid double processing
                    Cache::put('ws_outbound_queue', [], 86400);
                    
                    foreach ($queue as $msg) {
                        $event = $msg['event'] ?? '';
                        $data = $msg['data'] ?? [];
                        if ($event) {
                            $sent = $handler->broadcastEvent($event, $data);
                            Log::info("[WebSocket] Broadcasted event '{$event}' to {$sent} connection(s).");
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("[WebSocket Command] Error processing outbound queue: " . $e->getMessage());
            }
        });

        // Periodic timer for heartbeat checks (every 5 seconds)
        Loop::addPeriodicTimer(5.0, function () {
            try {
                $isOnline = Cache::get('jetson_ws_online', false);
                if ($isOnline) {
                    $lastHeartbeat = Cache::get('jetson_ws_last_heartbeat');
                    if ($lastHeartbeat && (now()->timestamp - $lastHeartbeat > 60)) {
                        Cache::put('jetson_ws_online', false, 86400);
                        Log::info("[WebSocket] Jetson marked offline due to heartbeat timeout (>60s)");
                    }
                }
            } catch (\Exception $e) {
                Log::error("[WebSocket Command] Error processing heartbeat check: " . $e->getMessage());
            }
        });

        $this->info("WebSocket server is running.");
        Loop::run();
    }
}
