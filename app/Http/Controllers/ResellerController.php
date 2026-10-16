<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResellerController extends Controller
{
    public function index(Request $request, OrganizationContext $context)
    {
        abort_unless($context->id(), 403);
        $admin = $request->user()->hasRole('super-admin');
        $accounts = DB::table('reseller_accounts')->where('organization_id', $context->id())
            ->when(! $admin, fn ($q) => $q->where('user_id', $request->user()->id))->get();
        $commissions = DB::table('reseller_commissions')->where('organization_id', $context->id())
            ->whereIn('reseller_account_id', $accounts->pluck('id'))->latest('created_at')->paginate(20);
        return view('resellers.index', ['organization' => $context->organization(), 'accounts' => $accounts, 'commissions' => $commissions, 'admin' => $admin]);
    }

    public function register(Request $request, OrganizationContext $context, AuditService $audit)
    {
        abort_unless($context->id(), 403);
        DB::transaction(function () use ($request, $context, $audit) {
            DB::table('organizations')->where('id', $context->id())->lockForUpdate()->first();
            $identity = ['organization_id' => $context->id(), 'user_id' => $request->user()->id];
            if (! DB::table('reseller_accounts')->where($identity)->exists()) {
                DB::table('reseller_accounts')->insert($identity + ['id' => (string) Str::uuid(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                $audit->record('reseller.requested', $request->user(), null, [], $context->organization());
            }
        });
        return back()->with('status', 'Reseller application recorded.');
    }

    public function update(Request $request, string $account, OrganizationContext $context, AuditService $audit)
    {
        abort_unless($request->user()->hasRole('super-admin') && $context->id(), 403);
        $data = $request->validate(['status' => ['required', 'in:approved,suspended'], 'commission_bps' => ['required', 'integer', 'between:0,10000']]);
        DB::transaction(function () use ($account, $context, $data, $audit, $request) {
            $record = DB::table('reseller_accounts')->where('organization_id', $context->id())->where('id', $account)->lockForUpdate()->first();
            abort_unless($record, 404);
            DB::table('reseller_accounts')->where('id', $account)->update($data + ['updated_at' => now()]);
            $audit->record('reseller.updated', $request->user(), null, ['account_id' => $account] + $data, $context->organization());
        });
        return back()->with('status', 'Reseller account updated. Existing order commissions remain unchanged.');
    }

    public function customer(Request $request, string $account, OrganizationContext $context, AuditService $audit)
    {
        abort_unless($request->user()->hasRole('super-admin') && $context->id(), 403);
        $data = $request->validate(['customer_id' => ['required', 'integer']]);
        DB::transaction(function () use ($account, $context, $data, $audit, $request) {
            DB::table('organizations')->where('id', $context->id())->lockForUpdate()->first();
            $record = DB::table('reseller_accounts')->where('organization_id', $context->id())->where('id', $account)->where('status', 'approved')->first();
            abort_unless($record, 404);
            abort_if((int) $record->user_id === (int) $data['customer_id'], 422, 'Self-referrals are not allowed.');
            abort_unless($context->organization()->members()->whereKey($data['customer_id'])->wherePivot('status', 'active')->exists(), 422, 'Customer must be an active member of this organization.');
            abort_if(DB::table('reseller_customers')->where('organization_id', $context->id())->where('customer_id', $data['customer_id'])->exists(), 409, 'Customer already assigned.');
            DB::table('reseller_customers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $context->id(), 'reseller_account_id' => $account, 'customer_id' => $data['customer_id'], 'created_at' => now(), 'updated_at' => now()]);
            $audit->record('reseller.customer_assigned', $request->user(), null, ['account_id' => $account, 'customer_id' => $data['customer_id']], $context->organization());
        });
        return back()->with('status', 'Customer assigned.');
    }
}
