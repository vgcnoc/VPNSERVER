<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MidtransService
{
    private function key(): string
    {
        $key = config('midtrans.server_key');
        abort_unless(is_string($key) && str_starts_with($key, 'SB-Mid-server-'), 503, 'Midtrans sandbox is not configured.');
        return $key;
    }

    public function checkout(string $invoiceId): string
    {
        $key = $this->key();
        $cached = DB::transaction(function () use ($invoiceId) {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            abort_unless($invoice && $invoice->status === 'unpaid', 409);
            $session = DB::table('payment_sessions')->where('invoice_id', $invoiceId)->first();
            if ($session) {
                abort_unless($session->status === 'ready', 409, 'Checkout outcome needs reconciliation. Do not create a duplicate payment.');
                return $session->redirect_url;
            }
            DB::table('payment_sessions')->insert(['invoice_id' => $invoiceId, 'status' => 'creating', 'created_at' => now(), 'updated_at' => now()]);
            return null;
        });
        if ($cached) {
            return $cached;
        }
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        try {
            $response = Http::withBasicAuth($key, '')->acceptJson()->timeout(20)->connectTimeout(5)
                ->post('https://app.sandbox.midtrans.com/snap/v1/transactions', [
                    'transaction_details' => ['order_id' => $invoiceId, 'gross_amount' => (int) $invoice->amount_idr],
                ]);
            $url = $response->json('redirect_url');
            if (! $response->successful() || ! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'app.sandbox.midtrans.com') {
                throw new \RuntimeException('Invalid checkout response.');
            }
        } catch (\Throwable $e) {
            DB::table('payment_sessions')->where('invoice_id', $invoiceId)->update(['status' => 'unknown', 'updated_at' => now()]);
            abort(503, 'Provider checkout unavailable; payment status must be reconciled before retrying.');
        }
        DB::table('payment_sessions')->where('invoice_id', $invoiceId)->update(['status' => 'ready', 'redirect_url' => $url, 'updated_at' => now()]);
        return $url;
    }

    public function notification(array $payload): void
    {
        $key = $this->key();
        $signature = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$key);
        abort_unless(hash_equals($signature, $payload['signature_key']), 403);
        $this->reconcile($payload['order_id']);
    }

    public function reconcile(string $invoiceId): void
    {
        abort_unless(Str::isUuid($invoiceId), 422);
        $key = $this->key();
        $payload = ['order_id' => $invoiceId];
        // The signature does not cover transaction_status. Retrieve authoritative state.
        try {
            $response = Http::withBasicAuth($key, '')->acceptJson()->timeout(15)->connectTimeout(5)
                ->get('https://api.sandbox.midtrans.com/v2/'.$payload['order_id'].'/status');
            abort_unless($response->successful(), 503);
            $status = $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            abort(503, 'Provider verification unavailable.');
        }
        abort_unless(is_array($status) && ($status['order_id'] ?? null) === $payload['order_id'], 422);
        $state = $status['transaction_status'] ?? '';
        $transaction = $status['transaction_id'] ?? '';
        abort_unless(is_string($state) && strlen($state) <= 30 && is_string($transaction) && strlen($transaction) > 0 && strlen($transaction) <= 100, 422);
        DB::transaction(function () use ($status, $state, $transaction, $payload) {
            $invoice = DB::table('invoices')->where('id', $payload['order_id'])->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(($status['currency'] ?? null) === 'IDR' && preg_match('/^'.preg_quote((string) $invoice->amount_idr, '/').'(?:\.00)?$/D', (string) ($status['gross_amount'] ?? '')), 422);
            $event = ['invoice_id' => $invoice->id, 'transaction_id' => $transaction, 'status' => $state];
            if (DB::table('payment_webhook_events')->where($event)->exists()) {
                return;
            }
            $paid = $state === 'settlement' || ($state === 'capture' && ($status['fraud_status'] ?? '') === 'accept');
            $outcome = 'recorded';
            if ($paid && $invoice->status === 'unpaid') {
                DB::table('payments')->insert(['id' => (string) Str::uuid(), 'organization_id' => $invoice->organization_id, 'invoice_id' => $invoice->id, 'provider' => 'midtrans-sandbox', 'reference' => $transaction, 'amount_idr' => $invoice->amount_idr, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
                DB::table('orders')->where('id', $invoice->order_id)->update(['status' => 'paid', 'updated_at' => now()]);
                $subscription = DB::table('subscriptions')->where('order_id', $invoice->order_id)->lockForUpdate()->first();
                DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'paid', 'updated_at' => now()]);
                DB::table('subscription_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $invoice->organization_id, 'subscription_id' => $subscription->id, 'from_status' => $subscription->status, 'to_status' => 'paid', 'created_at' => now()]);
                $outcome = 'payment_verified';
                app(ResellerCommissionService::class)->accrue(DB::table('payments')->where('invoice_id', $invoice->id)->value('id'));
            } elseif (in_array($state, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
                $outcome = 'requires_finance_review';
                $paymentId = DB::table('payments')->where('invoice_id', $invoice->id)->value('id');
                DB::table('reseller_commissions')->where('payment_id', $paymentId)->update(['status' => 'review_required', 'updated_at' => now()]);
            }
            DB::table('payment_webhook_events')->insert($event + ['id' => (string) Str::uuid(), 'provider' => 'midtrans-sandbox', 'outcome' => $outcome, 'created_at' => now()]);
        }, 3);
    }
}
