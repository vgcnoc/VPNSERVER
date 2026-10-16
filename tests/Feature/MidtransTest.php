<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\BillingService;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MidtransTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): string
    {
        config(['midtrans.server_key' => 'SB-Mid-server-test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $tenant = Organization::create(['name' => 'Tenant', 'slug' => 'tenant', 'status' => 'active']);
        $user->organizations()->attach($tenant->id, ['status' => 'active']);
        $plan = (string) Str::uuid();
        DB::table('plans')->insert(['id' => $plan, 'name' => 'Test', 'price_idr' => 50000, 'duration_days' => 30]);
        return app(BillingService::class)->order($user, $tenant, $plan, (string) Str::uuid());
    }

    private function payload(string $id): array
    {
        return ['order_id' => $id, 'status_code' => '200', 'gross_amount' => '50000.00', 'signature_key' => hash('sha512', $id.'20050000.00SB-Mid-server-test')];
    }

    public function test_verified_settlement_is_idempotent(): void
    {
        $id = $this->invoice();
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['order_id' => $id, 'transaction_status' => 'settlement', 'transaction_id' => 'tx-1', 'currency' => 'IDR', 'gross_amount' => '50000.00'])]);
        $this->postJson('/webhooks/midtrans', $this->payload($id))->assertOk();
        $this->postJson('/webhooks/midtrans', $this->payload($id))->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseHas('subscriptions', ['status' => 'paid', 'expires_at' => null]);
    }

    public function test_invalid_signature_does_not_call_provider(): void
    {
        $id = $this->invoice();
        Http::fake();
        $payload = $this->payload($id);
        $payload['signature_key'] = str_repeat('0', 128);
        $this->postJson('/webhooks/midtrans', $payload)->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_forged_status_is_ignored_in_favor_of_provider_status(): void
    {
        $id = $this->invoice();
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['order_id' => $id, 'transaction_status' => 'pending', 'transaction_id' => 'tx-1', 'currency' => 'IDR', 'gross_amount' => '50000.00'])]);
        $this->postJson('/webhooks/midtrans', $this->payload($id) + ['transaction_status' => 'settlement'])->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'unpaid']);
    }

    public function test_wrong_amount_is_rejected(): void
    {
        $id = $this->invoice();
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['order_id' => $id, 'transaction_status' => 'settlement', 'transaction_id' => 'tx-1', 'currency' => 'IDR', 'gross_amount' => '1.00'])]);
        $this->postJson('/webhooks/midtrans', $this->payload($id))->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_reuses_existing_session(): void
    {
        $id = $this->invoice();
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/test'])]);
        $service = app(MidtransService::class);
        $this->assertSame($service->checkout($id), $service->checkout($id));
        Http::assertSentCount(1);
    }

    public function test_provider_failure_does_not_mark_invoice_paid(): void
    {
        $id = $this->invoice();
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response([], 500)]);
        $this->postJson('/webhooks/midtrans', $this->payload($id))->assertStatus(503);
        $this->assertDatabaseCount('payments', 0);
    }
}
