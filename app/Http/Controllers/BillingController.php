<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlanRequest;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    public function index(Request $request, OrganizationContext $context)
    {
        $tenant = $context->organization();
        abort_unless($tenant, 403, 'An active organization is required.');
        $canManage = $request->user()->hasRole('super-admin') || $request->user()->hasPermission('billing.manage');
        $invoices = DB::table('invoices')->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->join('subscriptions', 'subscriptions.order_id', '=', 'orders.id')
            ->where('invoices.organization_id', $tenant->id)
            ->when(! $canManage, fn ($q) => $q->where('orders.customer_id', $request->user()->id))
            ->select('invoices.*', 'orders.customer_id', 'subscriptions.status as subscription_status')
            ->orderByDesc('invoices.created_at')->paginate(15);
        return view('billing.index', ['organization' => $tenant, 'plans' => DB::table('plans')->where('active', true)->get(), 'invoices' => $invoices, 'canManage' => $canManage]);
    }

    public function plan(StorePlanRequest $request, AuditService $audit)
    {
        DB::transaction(function () use ($request, $audit) {
            $id = (string) Str::uuid();
            DB::table('plans')->insert($request->validated() + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $audit->record('billing.plan_created', $request->user(), null, ['plan_id' => $id]);
        });
        return to_route('billing.index')->with('status', 'Package created.');
    }

    public function order(Request $request, OrganizationContext $context, BillingService $billing)
    {
        $data = $request->validate(['plan_id' => ['required', 'uuid'], 'idempotency_key' => ['required', 'uuid']]);
        abort_unless($context->organization(), 403);
        $billing->order($request->user(), $context->organization(), $data['plan_id'], $data['idempotency_key']);
        return to_route('billing.index')->with('status', 'Order and invoice created. Awaiting payment verification.');
    }

    public function approve(Request $request, string $invoice, OrganizationContext $context, BillingService $billing)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+$/', 'max:100'], 'amount_idr' => ['required', 'integer', 'min:1', 'max:1000000000'], 'confirmed' => ['accepted']]);
        abort_unless($context->organization(), 403);
        $billing->approveManual($request->user(), $context->organization(), $invoice, $data['reference'], (int) $data['amount_idr']);
        return to_route('billing.index')->with('status', 'Payment verified. Subscription awaits provisioning.');
    }
}
