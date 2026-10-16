<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function order(User $user, Organization $tenant, string $planId, string $key): string
    {
        abort_unless($user->belongsToOrganization($tenant), 403);

        return DB::transaction(function () use ($user, $tenant, $planId, $key) {
            DB::table('organizations')->where('id', $tenant->id)->lockForUpdate()->first();
            abort_unless($user->belongsToOrganization($tenant), 403);
            $existing = DB::table('orders')->where('organization_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->customer_id === $user->id && $existing->plan_id === $planId, 409);
                return DB::table('invoices')->where('order_id', $existing->id)->value('id');
            }
            $plan = DB::table('plans')->where('id', $planId)->where('active', true)->lockForUpdate()->first();
            abort_unless($plan, 422, 'Plan is unavailable.');
            $order = (string) Str::uuid();
            $subscription = (string) Str::uuid();
            $invoice = (string) Str::uuid();
            $base = ['organization_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now()];
            $reseller = DB::table('reseller_customers')->join('reseller_accounts', 'reseller_accounts.id', '=', 'reseller_customers.reseller_account_id')
                ->where('reseller_customers.organization_id', $tenant->id)->where('reseller_accounts.organization_id', $tenant->id)
                ->where('reseller_customers.customer_id', $user->id)->where('reseller_accounts.status', 'approved')
                ->select('reseller_accounts.id', 'reseller_accounts.commission_bps')->first();
            DB::table('orders')->insert($base + ['id' => $order, 'customer_id' => $user->id, 'plan_id' => $planId, 'idempotency_key' => $key]);
            if ($reseller) {
                DB::table('orders')->where('id', $order)->update(['reseller_account_id' => $reseller->id, 'commission_bps' => $reseller->commission_bps]);
            }
            DB::table('subscriptions')->insert($base + ['id' => $subscription, 'order_id' => $order, 'duration_days' => $plan->duration_days, 'peer_limit' => $plan->peer_limit]);
            DB::table('invoices')->insert($base + ['id' => $invoice, 'order_id' => $order, 'description' => $plan->name, 'amount_idr' => $plan->price_idr, 'due_at' => now()->addDay()]);
            app(AuditService::class)->record('billing.order_created', $user, null, ['order_id' => $order, 'invoice_id' => $invoice], $tenant);
            return $invoice;
        }, 3);
    }

    public function approveManual(User $actor, Organization $tenant, string $invoiceId, string $reference, int $amount): void
    {
        abort_unless($actor->hasRole('super-admin') || ($actor->belongsToOrganization($tenant) && $actor->hasPermission('billing.manage')), 403);
        DB::transaction(function () use ($actor, $tenant, $invoiceId, $reference, $amount) {
            $invoice = DB::table('invoices')->where('organization_id', $tenant->id)->where('id', $invoiceId)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            $order = DB::table('orders')->where('id', $invoice->order_id)->first();
            abort_if($order->customer_id === $actor->id, 403, 'A second authorized person must verify the payment.');
            if ((int) $invoice->amount_idr !== $amount) {
                throw ValidationException::withMessages(['amount_idr' => 'Amount must match the invoice exactly.']);
            }
            if ($invoice->status === 'paid') {
                $payment = DB::table('payments')->where('invoice_id', $invoiceId)->first();
                abort_unless($payment && $payment->provider === 'manual' && $payment->reference === $reference, 409);
                return;
            }
            $subscription = DB::table('subscriptions')->where('order_id', $invoice->order_id)->lockForUpdate()->first();
            DB::table('payments')->insert(['id' => (string) Str::uuid(), 'organization_id' => $tenant->id, 'invoice_id' => $invoiceId, 'provider' => 'manual', 'reference' => $reference, 'amount_idr' => $amount, 'verified_by' => $actor->id, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            DB::table('orders')->where('id', $invoice->order_id)->update(['status' => 'paid', 'updated_at' => now()]);
            DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'paid', 'updated_at' => now()]);
            DB::table('subscription_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $tenant->id, 'subscription_id' => $subscription->id, 'from_status' => $subscription->status, 'to_status' => 'paid', 'created_at' => now()]);
            app(AuditService::class)->record('billing.payment_verified', $actor, null, ['invoice_id' => $invoiceId, 'payment_method' => 'manual'], $tenant);
            app(ResellerCommissionService::class)->accrue(DB::table('payments')->where('invoice_id', $invoiceId)->value('id'));
        }, 3);
    }
}
