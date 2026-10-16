<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_without_tenant_cannot_read_platform_audit(): void
    {
        $user = User::factory()->create();
        \App\Models\AuditLog::create(['action' => 'private.platform.event', 'occurred_at' => now()]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('private.platform.event');
        $this->assertCount(0, $this->get(route('dashboard'))->viewData('events'));
    }

    public function test_suspended_membership_cannot_switch_organization(): void
    {
        [$user, $organization] = $this->createMember('customer');
        $user->organizations()->updateExistingPivot($organization->id, ['status' => 'suspended']);
        $this->actingAs($user)->post(route('organizations.switch', $organization))->assertForbidden();
    }

    public function test_inactive_organization_cannot_be_selected(): void
    {
        [$user, $organization] = $this->createMember('customer');
        $organization->update(['status' => 'suspended']);
        $this->actingAs($user)->post(route('organizations.switch', $organization))->assertForbidden();
        $this->withSession(['active_organization_id' => $organization->id])
            ->get(route('dashboard'))->assertViewHas('organization', null);
    }

    public function test_auditor_only_sees_active_tenant_events(): void
    {
        [$user, $organization] = $this->createMember('auditor');
        $permission = Permission::create(['name' => 'View audit', 'slug' => 'audit.view', 'group' => 'security']);
        $user->roles()->first()->permissions()->attach($permission->id);
        \App\Models\AuditLog::create(['organization_id' => $organization->id, 'action' => 'tenant.event', 'occurred_at' => now()]);
        \App\Models\AuditLog::create(['action' => 'platform.event', 'occurred_at' => now()]);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame(['tenant.event'], $response->viewData('events')->pluck('action')->all());
    }

    public function test_customer_cannot_read_organization_audit_without_permission(): void
    {
        [$user, $organization] = $this->createMember('customer');
        \App\Models\AuditLog::create(['organization_id' => $organization->id, 'action' => 'tenant.event', 'occurred_at' => now()]);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertCount(0, $response->viewData('events'));
    }

    public function test_super_admin_can_view_the_foundation_dashboard(): void
    {
        [$user, $organization] = $this->createMember('super-admin', true);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->getKey()])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Operations overview')
            ->assertSee('Foundation status');
    }

    public function test_member_can_open_organizations_and_sees_active_member_count(): void
    {
        [$user, $organization] = $this->createMember('customer');
        $organization->members()->attach(User::factory()->create()->id, ['status' => 'suspended']);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->getKey()])
            ->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('Organizations')
            ->assertSee('Currently active')
            ->assertSee('1');
    }

    public function test_user_cannot_switch_to_an_organization_they_do_not_belong_to(): void
    {
        [$user] = $this->createMember('customer');
        $otherOrganization = Organization::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->actingAs($user)
            ->post(route('organizations.switch', $otherOrganization))
            ->assertForbidden();
    }

    public function test_permissions_are_resolved_through_roles_not_the_frontend(): void
    {
        [$user] = $this->createMember('tenant-owner');
        $permission = Permission::query()->create([
            'name' => 'Manage organizations',
            'slug' => 'organizations.manage',
            'group' => 'organizations',
        ]);
        $user->roles()->firstOrFail()->permissions()->attach($permission->getKey());

        $this->assertTrue($user->hasPermission('organizations.manage'));
        $this->assertFalse($user->hasPermission('infrastructure.manage'));
    }

    /** @return array{0: User, 1: Organization} */
    private function createMember(string $roleSlug, bool $superAdmin = false): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Example Tenant',
            'slug' => 'example-tenant-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'timezone' => 'Asia/Jakarta',
        ]);
        $role = Role::query()->create([
            'name' => $superAdmin ? 'Super Admin' : 'Customer',
            'slug' => $roleSlug,
            'description' => 'Test role',
        ]);

        $user->organizations()->attach($organization->getKey(), ['is_owner' => true, 'status' => 'active']);
        $user->roles()->attach($role->getKey());

        return [$user, $organization];
    }
}
