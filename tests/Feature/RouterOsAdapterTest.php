<?php

namespace Tests\Feature;

use App\Models\VpnServer;
use App\Services\RouterOsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouterOsAdapterTest extends TestCase
{
    private function router(): RouterOsService
    {
        config(['routeros.enabled' => true, 'routeros.url' => 'https://chr.test', 'routeros.username' => 'svc', 'routeros.password' => 'test-secret', 'routeros.interface' => 'wg-test', 'routeros.public_key' => 'server-public']);
        Http::preventStrayRequests();
        return new RouterOsService();
    }

    public function test_existing_peer_is_verified_without_creating_duplicate(): void
    {
        $router = $this->router();
        $peer = (object) ['id' => 'test-peer', 'gateway_identity' => $router->identity(), 'public_key' => 'client-public', 'address' => '10.80.0.2'];
        Http::fake([
            'chr.test/rest/system/resource*' => Http::response([['version' => '7.20']]),
            'chr.test/rest/interface/wireguard/peers*' => Http::response([['.id' => '*1', 'interface' => 'wg-test', 'public-key' => 'client-public', 'allowed-address' => '10.80.0.2/32', 'disabled' => 'false']]),
            'chr.test/rest/interface/wireguard*' => Http::response([['name' => 'wg-test', 'public-key' => 'server-public', 'running' => 'true', 'disabled' => 'false']]),
        ]);
        $this->assertSame('*1', $router->provision($peer)['.id']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_gateway_change_blocks_mutation(): void
    {
        $router = $this->router();
        Http::fake();
        try {
            $router->suspend((object) ['gateway_identity' => 'different']);
            $this->fail('Expected rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('configuration changed', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_transport_error_is_sanitized(): void
    {
        $router = $this->router();
        Http::fake(['chr.test/*' => Http::response(['private-key' => 'do-not-log'], 500)]);
        try {
            $router->health();
            $this->fail('Expected rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('do-not-log', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_l2tp_health_check_verifies_the_routeros_service_is_enabled(): void
    {
        $server = VpnServer::make([
            'name' => 'L2TP test gateway',
            'protocol' => 'l2tp_ipsec',
            'endpoint' => 'https://chr.test',
            'username' => 'svc',
            'password' => 'test-secret',
            'protocol_secret' => 'ipsec-secret',
            'wireguard_interface' => 'wg-unused',
            'pool_prefix' => '10.81.0',
            'capacity' => 253,
            'status' => 'active',
        ]);
        $server->id = 'l2tp-test-server';
        Http::preventStrayRequests();
        Http::fake([
            'chr.test/rest/system/resource*' => Http::response([['version' => '7.20']]),
            'chr.test/rest/interface/l2tp-server/server*' => Http::response([['disabled' => 'false']]),
        ]);

        $this->assertSame('7.20', (new RouterOsService())->health($server)['version']);
        Http::assertSentCount(2);
    }
}
