<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Role;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant = Organization::create(['name' => 'Tenant', 'slug' => (string) Str::uuid(), 'status' => 'active']);
        $user = User::factory()->create();
        $user->organizations()->attach($tenant->id, ['status' => 'active']);
        $plan = (string) Str::uuid();
        DB::table('plans')->insert(['id' => $plan, 'name' => 'Remote', 'price_idr' => 50000, 'duration_days' => 30, 'peer_limit' => 1]);
        return [$user, $tenant, $plan];
    }

    public function test_duplicate_order_has_one_invoice_and_immutable_price(): void
    {
        [$user, $tenant, $plan] = $this->fixture();
        $key = (string) Str::uuid();
        $billing = app(BillingService::class);
        $id = $billing->order($user, $tenant, $plan, $key);
        DB::table('plans')->where('id', $plan)->update(['price_idr' => 99000]);
        $this->assertSame($id, $billing->order($user, $tenant, $plan, $key));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', ['id' => $id, 'amount_idr' => 50000]);
    }

    public function test_manual_payment_retry_does_not_activate_or_duplicate(): void
    {
        [$user, $tenant, $plan] = $this->fixture();
        $admin = User::factory()->create();
        $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
        $admin->roles()->attach($role->id);
        $billing = app(BillingService::class);
        $id = $billing->order($user, $tenant, $plan, (string) Str::uuid());
        $billing->approveManual($admin, $tenant, $id, 'BANK-001', 50000);
        $billing->approveManual($admin, $tenant, $id, 'BANK-001', 50000);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('subscription_events', 1);
        $this->assertDatabaseHas('subscriptions', ['status' => 'paid', 'expires_at' => null]);
    }

    public function test_foreign_tenant_order_is_rejected(): void
    {
        [$user, $tenant, $plan] = $this->fixture();
        $other = User::factory()->create();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BillingService::class)->order($other, $tenant, $plan, (string) Str::uuid());
    }

    public function test_customer_cannot_approve_payment(): void
    {
        [$user, $tenant, $plan] = $this->fixture();
        $id = app(BillingService::class)->order($user, $tenant, $plan, (string) Str::uuid());
        $this->actingAs($user)->post(route('billing.approve', $id), ['reference' => 'BANK-2', 'amount_idr' => 50000, 'confirmed' => '1'])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_billing_page_hides_other_customers_invoices(): void
    {
        [$user, $tenant, $plan] = $this->fixture();
        $other = User::factory()->create();
        $other->organizations()->attach($tenant->id, ['status' => 'active']);
        $id = app(BillingService::class)->order($other, $tenant, $plan, (string) Str::uuid());
        $this->actingAs($user)->get(route('billing.index'))->assertOk()->assertDontSee($id);
    }
}
