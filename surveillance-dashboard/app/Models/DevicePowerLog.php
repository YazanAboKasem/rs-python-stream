<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevicePowerLog extends Model
{
    protected $table = 'device_power_logs';

    protected $fillable = [
        'device_id',
        'started_at',
        'stopped_at',
        'reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];
}
