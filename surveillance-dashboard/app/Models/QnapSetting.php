<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QnapSetting extends Model
{
    protected $table = 'qnap_settings';

    protected $fillable = [
        'qnap_host',
        'qnap_port',
        'qnap_protocol',
        'qnap_username',
        'qnap_password',
        'qnap_remote_path',
    ];

    protected $casts = [
        'qnap_host' => 'encrypted',
        'qnap_username' => 'encrypted',
        'qnap_password' => 'encrypted',
    ];
}
