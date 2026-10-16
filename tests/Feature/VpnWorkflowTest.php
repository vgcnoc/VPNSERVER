<?php

namespace Tests\Feature;

use App\Jobs\ProvisionPeer;
use App\Models\Organization;
use App\Models\User;
use App\Services\BillingService;
use App\Services\RouterOsService;
use App\Services\VpnProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class VpnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrder(): array
    {
        Queue::fake();
        config(['routeros.pool_prefix' => '10.80.0', 'routeros.capacity' => 10, 'routeros.enabled' => true]);
        $user = User::factory()->create();
        $tenant = Organization::create(['name' => 'Tenant', 'slug' => 'tenant', 'status' => 'active']);
        $user->organizations()->attach($tenant->id, ['status' => 'active']);
        $plan = (string) Str::uuid();
        DB::table('plans')->insert(['id' => $plan, 'name' => 'Test', 'price_idr' => 50000, 'duration_days' => 30]);
        app(BillingService::class)->order($user, $tenant, $plan, (string) Str::uuid());
        $sub = DB::table('subscriptions')->first();
        return [$user, $tenant, $sub];
    }

    public function test_unpaid_subscription_cannot_allocate_ip(): void
    {
        [$user, $tenant, $sub] = $this->setupOrder();
        $this->actingAs($user)->post(route('vpn.provision', $sub->id), ['public_key' => base64_encode(random_bytes(32))])->assertStatus(409);
        $this->assertDatabaseCount('vpn_peers', 0);
    }

    public function test_duplicate_provisioning_retains_same_ip_and_peer(): void
    {
        [$user, $tenant, $sub] = $this->setupOrder();
        DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'paid']);
        $this->mock(RouterOsService::class)->shouldReceive('health')->once()->andReturn(['version' => '7.20']);
        $key = base64_encode(random_bytes(32));
        $service = app(VpnProvisioningService::class);
        $id = $service->request($user, $tenant, $sub->id, $key);
        $this->assertSame($id, $service->request($user, $tenant, $sub->id, $key));
        $this->assertDatabaseHas('vpn_peers', ['id' => $id, 'address' => '10.80.0.2']);
        $this->assertDatabaseCount('vpn_peers', 1);
        Queue::assertPushed(ProvisionPeer::class, 1);
    }

    public function test_provisioning_failure_keeps_ip_and_does_not_activate(): void
    {
        [$user, $tenant, $sub] = $this->setupOrder();
        DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'paid']);
        $router = $this->mock(RouterOsService::class);
        $router->shouldReceive('health')->andReturn(['version' => '7.20']);
        $id = app(VpnProvisioningService::class)->request($user, $tenant, $sub->id, base64_encode(random_bytes(32)));
        $router->shouldReceive('provision')->once()->andThrow(new \RuntimeException('Sensitive upstream detail'));
        try {
            (new ProvisionPeer($id))->handle($router);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('Sensitive', $e->getMessage());
        }
        $this->assertDatabaseHas('vpn_peers', ['id' => $id, 'status' => 'failed', 'address' => '10.80.0.2']);
        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id, 'status' => 'provisioning']);
        $this->assertDatabaseHas('provisioning_jobs', ['status' => 'failed', 'error_code' => 'GATEWAY_VERIFICATION_FAILED']);
    }

    public function test_handshake_activates_once_and_verified_expiry_suspends(): void
    {
        [$user, $tenant, $sub] = $this->setupOrder();
        DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'paid']);
        $router = $this->mock(RouterOsService::class);
        $router->shouldReceive('health')->andReturn(['version' => '7.20']);
        $id = app(VpnProvisioningService::class)->request($user, $tenant, $sub->id, base64_encode(random_bytes(32)));
        $router->shouldReceive('provision')->once()->andReturn(['last-handshake' => '12s', 'rx' => '123', 'tx' => '100']);
        (new ProvisionPeer($id))->handle($router);
        $this->assertDatabaseHas('subscriptions', ['status' => 'provisioning']);
        $router->shouldReceive('verifyExisting')->twice()->andReturn(['last-handshake' => '12s', 'rx' => '123', 'tx' => '100']);
        $this->artisan('vpn:reconcile')->assertSuccessful();
        $expires = DB::table('subscriptions')->where('id', $sub->id)->value('expires_at');
        $this->assertNotNull($expires);
        $this->artisan('vpn:reconcile')->assertSuccessful();
        $this->assertSame($expires, DB::table('subscriptions')->where('id', $sub->id)->value('expires_at'));
        $this->travel(31)->days();
        $router->shouldReceive('suspend')->once();
        $this->artisan('vpn:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('vpn_peers', ['id' => $id, 'address' => '10.80.0.2', 'status' => 'suspended']);
    }
}
