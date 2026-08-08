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
        'latitude',
        'longitude',
        'last_location_at',
    ];

    protected $casts = [
        'online' => 'boolean',
        'last_seen' => 'datetime',
        'last_location_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
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

    /**
     * Relationship: GPS location history for this agent.
     */
    public function locationLogs(): HasMany
    {
        return $this->hasMany(DeviceLocationLog::class, 'device_id', 'jetson_id');
    }
}
