<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResellerCommissionService
{
    public function accrue(string $paymentId): void
    {
        DB::transaction(function () use ($paymentId) {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment || DB::table('reseller_commissions')->where('payment_id', $paymentId)->exists()) {
                return;
            }
            $order = DB::table('orders')->join('invoices', 'invoices.order_id', '=', 'orders.id')
                ->where('invoices.id', $payment->invoice_id)->select('orders.*')->first();
            if (! $order?->reseller_account_id) {
                return;
            }
            $review = DB::table('payment_webhook_events')->where('invoice_id', $payment->invoice_id)
                ->where('outcome', 'requires_finance_review')->exists();
            DB::table('reseller_commissions')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $payment->organization_id,
                'reseller_account_id' => $order->reseller_account_id, 'payment_id' => $paymentId,
                'amount_idr' => intdiv((int) $payment->amount_idr * (int) $order->commission_bps, 10000),
                'status' => $review ? 'review_required' : 'accrued', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }
}
