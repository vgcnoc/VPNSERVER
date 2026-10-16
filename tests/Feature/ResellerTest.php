<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Role;
use App\Services\BillingService;
use App\Services\ResellerCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_uses_order_snapshot_and_cannot_duplicate(): void
    {
        $tenant = Organization::create(['name' => 'Tenant', 'slug' => 'tenant', 'status' => 'active']);
        $buyer = User::factory()->create();
        $reseller = User::factory()->create();
        $admin = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'super-admin']);
        $admin->roles()->attach($role->id);
        $buyer->organizations()->attach($tenant->id, ['status' => 'active']);
        $account = (string) Str::uuid();
        DB::table('reseller_accounts')->insert(['id' => $account, 'organization_id' => $tenant->id, 'user_id' => $reseller->id, 'status' => 'approved', 'commission_bps' => 1000]);
        DB::table('reseller_customers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $tenant->id, 'reseller_account_id' => $account, 'customer_id' => $buyer->id]);
        $plan = (string) Str::uuid();
        DB::table('plans')->insert(['id' => $plan, 'name' => 'Test', 'price_idr' => 50000, 'duration_days' => 30]);
        $invoice = app(BillingService::class)->order($buyer, $tenant, $plan, (string) Str::uuid());
        DB::table('reseller_accounts')->where('id', $account)->update(['commission_bps' => 2000]);
        app(BillingService::class)->approveManual($admin, $tenant, $invoice, 'BANK-100', 50000);
        app(ResellerCommissionService::class)->accrue(DB::table('payments')->value('id'));
        $this->assertDatabaseCount('reseller_commissions', 1);
        $this->assertDatabaseHas('reseller_commissions', ['amount_idr' => 5000]);
        $this->actingAs($buyer)->get(route('resellers.index'))->assertOk()->assertDontSee($account);
        $this->actingAs($buyer)->post(route('resellers.update', $account), ['status' => 'approved', 'commission_bps' => 10000])->assertForbidden();
    }
}
