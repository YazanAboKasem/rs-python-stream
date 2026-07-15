<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAgentCommand extends Model
{
    protected $fillable = [
        'jetson_id',
        'command',
        'payload',
        'status',
        'result',
        'executed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'executed_at' => 'datetime',
    ];

    /**
     * Relationship: The device agent this command belongs to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(DeviceAgent::class, 'jetson_id', 'jetson_id');
    }
}
