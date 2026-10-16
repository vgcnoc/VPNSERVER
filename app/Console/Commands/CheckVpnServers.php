<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use App\Services\RouterOsService;
use Illuminate\Console\Command;

class CheckVpnServers extends Command
{
    protected $signature = 'vpn:check-servers';
    protected $description = 'Check the configured RouterOS VPN gateways without changing peers';

    public function handle(RouterOsService $router): int
    {
        $failures = 0;

        VpnServer::query()->where('status', 'active')->orderBy('id')->each(function (VpnServer $server) use ($router, &$failures): void {
            try {
                $router->health($server);
                $server->update(['health_status' => 'healthy', 'last_checked_at' => now(), 'last_error' => null]);
                $this->info($server->name.': healthy');
            } catch (\Throwable) {
                $failures++;
                $server->update(['health_status' => 'unreachable', 'last_checked_at' => now(), 'last_error' => 'Connection or VPN service verification failed.']);
                $this->error($server->name.': unreachable or misconfigured');
            }
        });

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
