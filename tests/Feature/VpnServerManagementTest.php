<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\VpnServer;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VpnServerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_add_an_encrypted_default_vpn_server(): void
    {
        [$user, $organization] = $this->platformAdmin();
        $password = 'server-secret-not-in-plain-text';

        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])
            ->post(route('vpn-servers.store'), $this->payload($password))
            ->assertRedirect();

        $server = VpnServer::query()->firstOrFail();
        $this->assertTrue($server->is_default);
        $this->assertSame('active', $server->status);
        $this->assertSame($password, $server->password);
        $this->assertNotSame($password, DB::table('vpn_servers')->value('password'));
        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])
            ->get(route('vpn-servers.index'))->assertOk()->assertSee('Jakarta CHR 01')->assertSee('VPN server management');
    }

    public function test_non_admin_cannot_manage_vpn_servers(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Customer', 'slug' => 'customer-'.Str::lower(Str::random(6)), 'status' => 'active']);
        $user->organizations()->attach($organization->id, ['status' => 'active']);
        $role = Role::query()->create(['name' => 'Customer', 'slug' => 'customer', 'description' => 'Customer']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])
            ->get(route('vpn-servers.index'))->assertForbidden();
    }

    public function test_server_health_command_records_a_successful_check(): void
    {
        $server = VpnServer::query()->create($this->payload('encrypted-at-rest') + [
            'id' => (string) Str::uuid(),
            'status' => 'active',
            'health_status' => 'unknown',
        ]);
        $this->mock(RouterOsService::class)
            ->shouldReceive('health')
            ->once()
            ->withArgs(fn (VpnServer $candidate) => $candidate->id === $server->id)
            ->andReturn(['version' => '7.20']);

        $this->artisan('vpn:check-servers')->assertSuccessful();

        $this->assertDatabaseHas('vpn_servers', ['id' => $server->id, 'health_status' => 'healthy']);
    }

    /** @return array{0: User, 1: Organization} */
    private function platformAdmin(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Platform', 'slug' => 'platform-'.Str::lower(Str::random(6)), 'status' => 'active']);
        $user->organizations()->attach($organization->id, ['status' => 'active', 'is_owner' => true]);
        $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'description' => 'Platform administrator']);
        $user->roles()->attach($role->id);

        return [$user, $organization];
    }

    /** @return array<string, mixed> */
    private function payload(string $password): array
    {
        return [
            'name' => 'Jakarta CHR 01',
            'endpoint' => 'https://chr-01.example.test',
            'username' => 'vpnrent-api',
            'password' => $password,
            'wireguard_interface' => 'wg-vpnrent',
            'wireguard_public_key' => base64_encode(random_bytes(32)),
            'pool_prefix' => '10.80.0',
            'capacity' => 100,
        ];
    }
}
