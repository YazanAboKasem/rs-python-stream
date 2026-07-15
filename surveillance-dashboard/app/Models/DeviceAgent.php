<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceAgent extends Model
{
    protected $fillable = [
        'jetson_id',
        'hostname',
        'agent_version',
        'online',
        'last_seen',
        'uptime',
        'cpu',
        'ram',
        'disk',
        'temperature',
        'system_info',
    ];

    protected $casts = [
        'online' => 'boolean',
        'last_seen' => 'datetime',
        'system_info' => 'array',
    ];

    /**
     * Relationship: Commands sent to this agent.
     */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceAgentCommand::class, 'jetson_id', 'jetson_id');
    }

    /**
     * Relationship: Terminal sessions for this agent.
     */
    public function terminalSessions(): HasMany
    {
        return $this->hasMany(DeviceTerminalSession::class, 'jetson_id', 'jetson_id');
    }
}
