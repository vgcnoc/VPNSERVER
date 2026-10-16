<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VpnServer extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'protocol', 'endpoint', 'username', 'password', 'protocol_secret', 'certificate_name', 'wireguard_interface',
        'wireguard_public_key', 'pool_prefix', 'capacity', 'status', 'is_default',
        'health_status', 'last_checked_at', 'last_error',
    ];

    protected $hidden = ['password', 'protocol_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'protocol_secret' => 'encrypted',
            'is_default' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }
}
