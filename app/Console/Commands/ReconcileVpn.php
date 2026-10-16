<?php

namespace App\Console\Commands;

use App\Services\RouterOsService;
use App\Jobs\ProvisionPeer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileVpn extends Command
{
    protected $signature = 'vpn:reconcile';
    protected $description = 'Verify gateway peers and suspend expired subscriptions';

    public function handle(RouterOsService $router): int
    {
        if (! config('routeros.enabled')) {
            $this->warn('RouterOS is not enabled. No gateway actions performed.');
            return self::FAILURE;
        }
        $errors = 0;
        DB::table('vpn_peers')->orderBy('id')->chunk(50, function ($peers) use ($router, &$errors) {
            foreach ($peers as $candidate) {
                try {
                    DB::transaction(function () use ($candidate, $router) {
                        DB::table('gateway_locks')->where('id', 'primary')->lockForUpdate()->first();
                        $peer = DB::table('vpn_peers')->where('id', $candidate->id)->lockForUpdate()->first();
                        $sub = DB::table('subscriptions')->where('id', $peer->subscription_id)->lockForUpdate()->first();
                        if ($peer->status === 'queued') {
                            ProvisionPeer::dispatch($peer->id)->afterCommit();
                            return;
                        }
                        if (! in_array($peer->status, ['verified', 'suspending'], true)) {
                            return;
                        }
                        if ($sub->status === 'active' && $sub->expires_at && now()->gte($sub->expires_at)) {
                            $router->suspend($peer);
                            DB::table('vpn_peers')->where('id', $peer->id)->update(['status' => 'suspended', 'checked_at' => now(), 'updated_at' => now()]);
                            $this->transition($sub, 'suspended');
                            return;
                        }
                        $remote = $router->verifyExisting($peer);
                        $elapsed = $remote['last-handshake'] ?? null;
                        DB::table('vpn_peers')->where('id', $peer->id)->update(['last_handshake' => is_scalar($elapsed) ? substr((string) $elapsed, 0, 100) : null, 'rx' => ctype_digit((string) ($remote['rx'] ?? '')) ? (int) $remote['rx'] : null, 'tx' => ctype_digit((string) ($remote['tx'] ?? '')) ? (int) $remote['tx'] : null, 'checked_at' => now(), 'updated_at' => now()]);
                        // Accept only unambiguous positive seconds; zero may mean never connected.
                        if ($sub->status === 'provisioning' && is_scalar($elapsed) && preg_match('/^([1-9][0-9]*)(?:s)?$/D', (string) $elapsed, $match) && (int) $match[1] <= 180) {
                            $this->transition($sub, 'active', ['expires_at' => now()->addDays($sub->duration_days)]);
                        }
                    });
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error('Gateway reconciliation failed for peer '.$candidate->id.'; no success state was recorded.');
                }
            }
        });
        return $errors ? self::FAILURE : self::SUCCESS;
    }

    private function transition(object $sub, string $to, array $extra = []): void
    {
        DB::table('subscriptions')->where('id', $sub->id)->update(['status' => $to, 'updated_at' => now()] + $extra);
        DB::table('subscription_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $sub->organization_id, 'subscription_id' => $sub->id, 'from_status' => $sub->status, 'to_status' => $to, 'created_at' => now()]);
    }
}
