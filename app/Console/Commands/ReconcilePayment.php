<?php

namespace App\Console\Commands;

use App\Services\MidtransService;
use Illuminate\Console\Command;

class ReconcilePayment extends Command
{
    protected $signature = 'payments:reconcile {invoice : Invoice UUID}';
    protected $description = 'Reconcile an invoice against the Midtrans sandbox status API';

    public function handle(MidtransService $provider): int
    {
        try {
            $provider->reconcile($this->argument('invoice'));
            $this->info('Provider status verified and recorded. Review the invoice and webhook event outcome.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Reconciliation failed. Verify configuration, invoice ID, and provider connectivity. No successful payment was assumed.');
            return self::FAILURE;
        }
    }
}
