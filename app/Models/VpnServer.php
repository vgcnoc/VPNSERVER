<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VpnServer extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'endpoint', 'username', 'password', 'wireguard_interface',
        'wireguard_public_key', 'pool_prefix', 'capacity', 'status', 'is_default',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_default' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }
}
