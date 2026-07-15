<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceTerminalSession extends Model
{
    protected $fillable = [
        'jetson_id',
        'command_id',
        'port',
        'status',
        'timeout_minutes',
        'connection_string',
        'opened_at',
        'closed_at',
        'expires_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Relationship: The device agent this terminal session is opened for.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(DeviceAgent::class, 'jetson_id', 'jetson_id');
    }

    /**
     * Relationship: The command that triggered this session.
     */
    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceAgentCommand::class, 'command_id');
    }
}
