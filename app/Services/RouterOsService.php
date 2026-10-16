<?php

namespace App\Services;

use App\Models\VpnServer;
use Illuminate\Support\Facades\Http;

class RouterOsService
{
    public function identity(?VpnServer $server = null): string
    {
        $settings = $this->settings($server);

        return hash('sha256', json_encode([$server?->id, $settings['url'], $settings['interface'], $settings['public_key'], $settings['pool_prefix']]));
    }

    /** @return array{enabled: bool, url: mixed, username: mixed, password: mixed, interface: mixed, public_key: mixed, pool_prefix: mixed, capacity: int} */
    private function settings(?VpnServer $server): array
    {
        return $server
            ? [
                // Draft gateways may be tested, but only active gateways are selected for provisioning.
                'enabled' => $server->status !== 'disabled',
                'url' => $server->endpoint,
                'username' => $server->username,
                'password' => $server->password,
                'interface' => $server->wireguard_interface,
                'public_key' => $server->wireguard_public_key,
                'pool_prefix' => $server->pool_prefix,
                'capacity' => (int) $server->capacity,
            ]
            : [
                'enabled' => (bool) config('routeros.enabled'),
                'url' => config('routeros.url'),
                'username' => config('routeros.username'),
                'password' => config('routeros.password'),
                'interface' => config('routeros.interface'),
                'public_key' => config('routeros.public_key'),
                'pool_prefix' => config('routeros.pool_prefix'),
                'capacity' => (int) config('routeros.capacity'),
            ];
    }

    private function assertGateway(object $peer, ?VpnServer $server): void
    {
        if (($peer->vpn_server_id ?? null) !== $server?->id || ($peer->gateway_identity ?? '') !== $this->identity($server)) {
            throw new \RuntimeException('Gateway configuration changed; review the existing allocation before proceeding.');
        }
    }

    private function call(?VpnServer $server, string $method, string $path, array $data = []): array
    {
        $settings = $this->settings($server);
        $url = $settings['url'];
        if (! $settings['enabled'] || ! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)
            || ! $settings['username'] || ! $settings['password']) {
            throw new \RuntimeException('RouterOS is not configured.');
        }
        try {
            $response = Http::withBasicAuth((string) $settings['username'], (string) $settings['password'])
                ->acceptJson()->timeout(10)->connectTimeout(3)->withoutRedirecting()
                ->send($method, rtrim($url, '/').'/rest/'.$path, [$method === 'GET' ? 'query' : 'json' => $data]);
            if (! $response->successful() || ! is_array($response->json())) {
                throw new \RuntimeException();
            }

            return $response->json();
        } catch (\Throwable) {
            // Never expose provider bodies, credentials, or transport exceptions to logs.
            throw new \RuntimeException('RouterOS request failed; check secure connectivity and permissions.');
        }
    }

    public function health(?VpnServer $server = null): array
    {
        $settings = $this->settings($server);
        $resource = $this->call($server, 'GET', 'system/resource', ['.proplist' => 'version,cpu-load,free-memory,total-memory,uptime']);
        if (! isset($resource[0]['version']) || ! str_starts_with($resource[0]['version'], '7.')) {
            throw new \RuntimeException('RouterOS 7 is required.');
        }
        $interfaces = $this->call($server, 'GET', 'interface/wireguard', ['name' => $settings['interface'], '.proplist' => 'name,public-key,running,disabled']);
        if (count($interfaces) !== 1 || ($interfaces[0]['running'] ?? '') !== 'true'
            || ($interfaces[0]['disabled'] ?? '') !== 'false'
            || ! $settings['public_key'] || ($interfaces[0]['public-key'] ?? '') !== $settings['public_key']) {
            throw new \RuntimeException('WireGuard interface does not match the approved configuration.');
        }

        return $resource[0];
    }

    public function peer(string $id, ?VpnServer $server = null): ?array
    {
        $peers = $this->call($server, 'GET', 'interface/wireguard/peers', [
            'comment' => 'vpnrent:'.$id,
            '.proplist' => '.id,interface,public-key,allowed-address,disabled,last-handshake,rx,tx',
        ]);
        if (count($peers) > 1) {
            throw new \RuntimeException('Duplicate gateway peers require infrastructure review.');
        }

        return $peers[0] ?? null;
    }

    private function verify(array $remote, object $peer, array $settings): void
    {
        if (($remote['public-key'] ?? '') !== $peer->public_key || ($remote['interface'] ?? '') !== $settings['interface']
            || ($remote['allowed-address'] ?? '') !== $peer->address.'/32') {
            throw new \RuntimeException('Gateway peer identity or route mismatch; manual review required.');
        }
    }

    public function provision(object $peer, ?VpnServer $server = null): array
    {
        $settings = $this->settings($server);
        $this->assertGateway($peer, $server);
        $this->health($server);
        $remote = $this->peer($peer->id, $server);
        if (! $remote) {
            $this->call($server, 'PUT', 'interface/wireguard/peers', [
                'interface' => $settings['interface'], 'public-key' => $peer->public_key,
                'allowed-address' => $peer->address.'/32', 'comment' => 'vpnrent:'.$peer->id, 'disabled' => 'false',
            ]);
            $remote = $this->peer($peer->id, $server);
        }
        if (! $remote) {
            throw new \RuntimeException('Peer creation could not be verified.');
        }
        $this->verify($remote, $peer, $settings);
        if (($remote['disabled'] ?? '') !== 'false') {
            throw new \RuntimeException('Peer is disabled; review before reactivation.');
        }

        return $remote;
    }

    /** Reconciliation never creates or re-enables a missing peer. */
    public function verifyExisting(object $peer, ?VpnServer $server = null): array
    {
        $settings = $this->settings($server);
        $this->assertGateway($peer, $server);
        $this->health($server);
        $remote = $this->peer($peer->id, $server);
        if (! $remote) {
            throw new \RuntimeException('Peer is missing; manual review is required.');
        }
        $this->verify($remote, $peer, $settings);
        if (($remote['disabled'] ?? '') !== 'false') {
            throw new \RuntimeException('Peer is disabled; manual review is required.');
        }

        return $remote;
    }

    public function suspend(object $peer, ?VpnServer $server = null): void
    {
        $settings = $this->settings($server);
        $this->assertGateway($peer, $server);
        $remote = $this->peer($peer->id, $server);
        if (! $remote) {
            throw new \RuntimeException('Peer missing; suspension requires reconciliation.');
        }
        $this->verify($remote, $peer, $settings);
        if (! preg_match('/^\*[0-9A-Fa-f]+$/D', $remote['.id'] ?? '')) {
            throw new \RuntimeException('Invalid RouterOS record identifier.');
        }
        $this->call($server, 'PATCH', 'interface/wireguard/peers/'.rawurlencode($remote['.id']), ['disabled' => 'true']);
        $result = $this->peer($peer->id, $server);
        if (! $result || ($result['disabled'] ?? '') !== 'true') {
            throw new \RuntimeException('Suspension could not be verified.');
        }
        $this->verify($result, $peer, $settings);
    }
}
