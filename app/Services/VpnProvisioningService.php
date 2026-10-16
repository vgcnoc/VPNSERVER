<?php

namespace App\Services;

use App\Jobs\ProvisionPeer;
use App\Models\Organization;
use App\Models\User;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VpnProvisioningService
{
    public function request(User $user, Organization $tenant, string $subscriptionId, string $protocol, ?string $publicKey = null): string
    {
        abort_unless($user->belongsToOrganization($tenant), 403);
        abort_unless(in_array($protocol, ['wireguard', 'l2tp_ipsec', 'sstp', 'openvpn'], true), 422, 'Choose a supported VPN protocol.');
        if ($protocol === 'wireguard') {
            $decoded = base64_decode((string) $publicKey, true);
            abort_unless($decoded !== false && strlen($decoded) === 32 && base64_encode($decoded) === $publicKey && $decoded !== str_repeat("\0", 32), 422, 'A canonical WireGuard public key is required.');
        }

        return DB::transaction(function () use ($user, $tenant, $subscriptionId, $protocol, $publicKey) {
            DB::table('gateway_locks')->where('id', 'primary')->lockForUpdate()->first();
            $subscription = DB::table('subscriptions')->where('organization_id', $tenant->id)->where('id', $subscriptionId)->lockForUpdate()->first();
            abort_unless($subscription, 404);
            $order = DB::table('orders')->where('id', $subscription->order_id)->first();
            abort_unless((int) $order->customer_id === (int) $user->id, 403);
            $existing = DB::table('vpn_peers')->where('subscription_id', $subscriptionId)->first();
            if ($existing) {
                abort_unless($existing->protocol === $protocol && ($protocol !== 'wireguard' || $existing->public_key === $publicKey), 409, 'This subscription already has a different VPN connection.');
                return $existing->id;
            }
            abort_unless($subscription->status === 'paid', 409, 'Verified payment is required.');
            if ($protocol === 'wireguard') {
                abort_if(DB::table('vpn_peers')->where('public_key', $publicKey)->exists(), 409, 'Public key is already allocated.');
            }
            $server = $protocol === 'wireguard'
                ? VpnServer::query()->where('status', 'active')->where('protocol', 'wireguard')->where('is_default', true)->first()
                : VpnServer::query()->where('status', 'active')->where('protocol', $protocol)->orderByDesc('health_status')->orderBy('name')->first();
            if ($protocol === 'wireguard') {
                abort_if(VpnServer::query()->where('status', 'active')->where('protocol', 'wireguard')->exists() && ! $server, 503, 'Choose an active default WireGuard server before provisioning.');
            } else {
                abort_unless($server, 503, 'No active server is available for the selected protocol.');
            }
            $router = app(RouterOsService::class);
            $router->health($server);
            $prefix = $server?->pool_prefix ?? config('routeros.pool_prefix');
            abort_unless(is_string($prefix) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $prefix) && filter_var($prefix.'.1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), 503, 'A reserved IPv4 /24 pool is required.');
            $count = DB::table('vpn_peers')->when($server, fn ($q) => $q->where('vpn_server_id', $server->id), fn ($q) => $q->whereNull('vpn_server_id'))->count();
            $capacity = $server?->capacity ?? config('routeros.capacity');
            abort_if($count >= min(253, max(0, (int) $capacity)), 409, 'Gateway capacity reached.');
            $address = null;
            for ($host = 2; $host <= 254; $host++) {
                if (! DB::table('vpn_peers')->where('address', $prefix.'.'.$host)->exists()) {
                    $address = $prefix.'.'.$host;
                    break;
                }
            }
            abort_unless($address, 409, 'IP pool exhausted.');
            $id = (string) Str::uuid();
            $username = $protocol === 'wireguard' ? null : 'vpn'.substr(str_replace('-', '', $id), 0, 16);
            $secret = $protocol === 'wireguard' ? null : 'Vpn'.Str::random(21);
            // A unique opaque value preserves the legacy non-null public-key column for PPP protocols.
            $storedPublicKey = $protocol === 'wireguard' ? $publicKey : base64_encode(random_bytes(32));
            $identity = (new RouterOsService())->identity($server);
            DB::table('vpn_peers')->insert(['id' => $id, 'organization_id' => $tenant->id, 'subscription_id' => $subscriptionId, 'vpn_server_id' => $server?->id, 'protocol' => $protocol, 'public_key' => $storedPublicKey, 'username' => $username, 'connection_secret' => $secret ? Crypt::encryptString($secret) : null, 'gateway_identity' => $identity, 'address' => $address, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('subscriptions')->where('id', $subscriptionId)->update(['status' => 'provisioning', 'updated_at' => now()]);
            DB::table('subscription_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $tenant->id, 'subscription_id' => $subscriptionId, 'from_status' => 'paid', 'to_status' => 'provisioning', 'created_at' => now()]);
            app(AuditService::class)->record('vpn.provisioning_requested', $user, null, ['peer_id' => $id, 'protocol' => $protocol], $tenant);
            ProvisionPeer::dispatch($id)->afterCommit();
            return $id;
        }, 3);
    }
}
