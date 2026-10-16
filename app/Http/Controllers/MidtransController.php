<?php

namespace App\Http\Controllers;

use App\Services\MidtransService;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MidtransController extends Controller
{
    public function checkout(Request $request, string $invoice, OrganizationContext $context, MidtransService $midtrans)
    {
        abort_unless($context->id(), 403);
        $record = DB::table('invoices')->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->where('invoices.id', $invoice)->where('invoices.organization_id', $context->id())
            ->where('orders.customer_id', $request->user()->id)->first();
        abort_unless($record, 404);
        return redirect()->away($midtrans->checkout($invoice));
    }

    public function notification(Request $request, MidtransService $midtrans)
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'], 'status_code' => ['required', 'string', 'size:3'],
            'gross_amount' => ['required', 'string', 'regex:/^[0-9]{1,12}(?:\.[0-9]{2})?$/D'],
            'signature_key' => ['required', 'string', 'size:128'],
        ]);
        $midtrans->notification($data);
        return response()->json(['received' => true]);
    }
}
