<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RouterOsService
{
    public function identity(): string
    {
        return hash('sha256', json_encode([config('routeros.url'), config('routeros.interface'), config('routeros.public_key'), config('routeros.pool_prefix')]));
    }

    private function assertGateway(object $peer): void
    {
        if (($peer->gateway_identity ?? '') !== $this->identity()) {
            throw new \RuntimeException('Gateway configuration changed; review the existing allocation before proceeding.');
        }
    }

    private function call(string $method, string $path, array $data = []): array
    {
        $url = config('routeros.url');
        if (! config('routeros.enabled') || ! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)
            || ! config('routeros.username') || ! config('routeros.password')) {
            throw new \RuntimeException('RouterOS is not configured.');
        }
        try {
            $response = Http::withBasicAuth(config('routeros.username'), config('routeros.password'))
                ->acceptJson()->timeout(10)->connectTimeout(3)->withoutRedirecting()
                ->send($method, rtrim($url, '/').'/rest/'.$path, [$method === 'GET' ? 'query' : 'json' => $data]);
            if (! $response->successful() || ! is_array($response->json())) {
                throw new \RuntimeException();
            }
            return $response->json();
        } catch (\Throwable $e) {
            // Never expose provider bodies, credentials, or transport exceptions to logs.
            throw new \RuntimeException('RouterOS request failed; check secure connectivity and permissions.');
        }
    }

    public function health(): array
    {
        $resource = $this->call('GET', 'system/resource', ['.proplist' => 'version,cpu-load,free-memory,total-memory,uptime']);
        if (! isset($resource[0]['version']) || ! str_starts_with($resource[0]['version'], '7.')) {
            throw new \RuntimeException('RouterOS 7 is required.');
        }
        $interfaces = $this->call('GET', 'interface/wireguard', ['name' => config('routeros.interface'), '.proplist' => 'name,public-key,running,disabled']);
        if (count($interfaces) !== 1 || ($interfaces[0]['running'] ?? '') !== 'true'
            || ($interfaces[0]['disabled'] ?? '') !== 'false'
            || ! config('routeros.public_key') || ($interfaces[0]['public-key'] ?? '') !== config('routeros.public_key')) {
            throw new \RuntimeException('WireGuard interface does not match the approved configuration.');
        }
        return $resource[0];
    }

    public function peer(string $id): ?array
    {
        $peers = $this->call('GET', 'interface/wireguard/peers', [
            'comment' => 'vpnrent:'.$id,
            '.proplist' => '.id,interface,public-key,allowed-address,disabled,last-handshake,rx,tx',
        ]);
        if (count($peers) > 1) {
            throw new \RuntimeException('Duplicate gateway peers require infrastructure review.');
        }
        return $peers[0] ?? null;
    }

    private function verify(array $remote, object $peer): void
    {
        if (($remote['public-key'] ?? '') !== $peer->public_key || ($remote['interface'] ?? '') !== config('routeros.interface')
            || ($remote['allowed-address'] ?? '') !== $peer->address.'/32') {
            throw new \RuntimeException('Gateway peer identity or route mismatch; manual review required.');
        }
    }

    public function provision(object $peer): array
    {
        $this->assertGateway($peer);
        $this->health();
        $remote = $this->peer($peer->id);
        if (! $remote) {
            $this->call('PUT', 'interface/wireguard/peers', [
                'interface' => config('routeros.interface'), 'public-key' => $peer->public_key,
                'allowed-address' => $peer->address.'/32', 'comment' => 'vpnrent:'.$peer->id, 'disabled' => 'false',
            ]);
            $remote = $this->peer($peer->id);
        }
        if (! $remote) {
            throw new \RuntimeException('Peer creation could not be verified.');
        }
        $this->verify($remote, $peer);
        if (($remote['disabled'] ?? '') !== 'false') {
            throw new \RuntimeException('Peer is disabled; review before reactivation.');
        }
        return $remote;
    }

    /**
     * Reconciliation must never create or re-enable a missing peer.  Creation
     * is intentionally limited to the explicit provisioning job above.
     */
    public function verifyExisting(object $peer): array
    {
        $this->assertGateway($peer);
        $this->health();
        $remote = $this->peer($peer->id);
        if (! $remote) {
            throw new \RuntimeException('Peer is missing; manual review is required.');
        }
        $this->verify($remote, $peer);
        if (($remote['disabled'] ?? '') !== 'false') {
            throw new \RuntimeException('Peer is disabled; manual review is required.');
        }

        return $remote;
    }

    public function suspend(object $peer): void
    {
        $this->assertGateway($peer);
        $remote = $this->peer($peer->id);
        if (! $remote) {
            throw new \RuntimeException('Peer missing; suspension requires reconciliation.');
        }
        $this->verify($remote, $peer);
        if (! preg_match('/^\*[0-9A-Fa-f]+$/D', $remote['.id'] ?? '')) {
            throw new \RuntimeException('Invalid RouterOS record identifier.');
        }
        $this->call('PATCH', 'interface/wireguard/peers/'.rawurlencode($remote['.id']), ['disabled' => 'true']);
        $result = $this->peer($peer->id);
        if (! $result || ($result['disabled'] ?? '') !== 'true') {
            throw new \RuntimeException('Suspension could not be verified.');
        }
        $this->verify($result, $peer);
    }
}
