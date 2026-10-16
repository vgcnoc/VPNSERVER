<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Services\RouterOsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionPeer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 80;

    public function __construct(public string $peerId) {}

    public function backoff(): array { return [30, 120, 300]; }

    public function handle(RouterOsService $router): void
    {
        // Serialize primary-gateway mutations across queue retries and workers.
        $failed = DB::transaction(function () use ($router) {
            DB::table('gateway_locks')->where('id', 'primary')->lockForUpdate()->first();
            $peer = DB::table('vpn_peers')->where('id', $this->peerId)->lockForUpdate()->first();
            if (! $peer || in_array($peer->status, ['verified', 'suspended', 'suspending'], true)) {
                return false;
            }
            $subscription = DB::table('subscriptions')->where('id', $peer->subscription_id)->first();
            if ($subscription->status !== 'provisioning') {
                return false;
            }
            $jobId = (string) Str::uuid();
            DB::table('provisioning_jobs')->insert(['id' => $jobId, 'organization_id' => $peer->organization_id, 'vpn_peer_id' => $peer->id, 'action' => 'provision', 'status' => 'running', 'attempt' => $this->attempts(), 'created_at' => now(), 'updated_at' => now()]);
            try {
                $server = $peer->vpn_server_id ? VpnServer::query()->find($peer->vpn_server_id) : null;
                $router->provision($peer, $server);
                DB::table('vpn_peers')->where('id', $peer->id)->update(['status' => 'verified', 'checked_at' => now(), 'updated_at' => now()]);
                if (($peer->protocol ?? 'wireguard') !== 'wireguard') {
                    DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'active', 'expires_at' => now()->addDays($subscription->duration_days), 'updated_at' => now()]);
                    DB::table('subscription_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $subscription->organization_id, 'subscription_id' => $subscription->id, 'from_status' => 'provisioning', 'to_status' => 'active', 'created_at' => now()]);
                }
                DB::table('provisioning_jobs')->where('id', $jobId)->update(['status' => 'succeeded', 'updated_at' => now()]);
                return false;
            } catch (\Throwable $e) {
                DB::table('vpn_peers')->where('id', $peer->id)->update(['status' => 'failed', 'updated_at' => now()]);
                DB::table('provisioning_jobs')->where('id', $jobId)->update(['status' => 'failed', 'error_code' => 'GATEWAY_VERIFICATION_FAILED', 'updated_at' => now()]);
                return true;
            }
        });
        if ($failed) {
            throw new \RuntimeException('Provisioning verification failed; see the job history.');
        }
    }
}
