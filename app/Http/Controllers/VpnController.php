<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionPeer;
use App\Services\VpnProvisioningService;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VpnController extends Controller
{
    public function index(Request $request, OrganizationContext $context)
    {
        abort_unless($context->id(), 403);
        $subscriptions = DB::table('subscriptions')->join('orders', 'orders.id', '=', 'subscriptions.order_id')
            ->where('subscriptions.organization_id', $context->id())->where('orders.customer_id', $request->user()->id)
            ->select('subscriptions.*')->get();
        $peers = DB::table('vpn_peers')->where('organization_id', $context->id())->whereIn('subscription_id', $subscriptions->pluck('id'))->get();
        $jobs = DB::table('provisioning_jobs')->where('organization_id', $context->id())->whereIn('vpn_peer_id', $peers->pluck('id'))->latest('created_at')->limit(30)->get();
        return view('vpn.index', ['organization' => $context->organization(), 'subscriptions' => $subscriptions, 'peers' => $peers, 'jobs' => $jobs]);
    }

    public function provision(Request $request, string $subscription, OrganizationContext $context, VpnProvisioningService $service)
    {
        abort_unless($context->organization(), 403);
        $data = $request->validate(['public_key' => ['required', 'string', 'size:44']]);
        try {
            $service->request($request->user(), $context->organization(), $subscription, $data['public_key']);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->withErrors(['gateway' => 'Gateway verification failed. Contact the infrastructure administrator.']);
        }
        return back()->with('status', 'Provisioning queued. Keep the private key on your own device.');
    }

    public function retry(Request $request, string $peer, OrganizationContext $context)
    {
        abort_unless($context->id(), 403);
        DB::transaction(function () use ($request, $peer, $context) {
            DB::table('gateway_locks')->where('id', 'primary')->lockForUpdate()->first();
            $record = DB::table('vpn_peers')->where('organization_id', $context->id())->where('id', $peer)->first();
            abort_unless($record, 404);
            $sub = DB::table('subscriptions')->where('id', $record->subscription_id)->first();
            $order = DB::table('orders')->where('id', $sub->order_id)->first();
            abort_unless((int) $order->customer_id === (int) $request->user()->id, 403);
            abort_unless($record->status === 'failed' && $sub->status === 'provisioning', 409);
            DB::table('vpn_peers')->where('id', $peer)->update(['status' => 'queued', 'updated_at' => now()]);
            ProvisionPeer::dispatch($peer)->afterCommit();
        });
        return back()->with('status', 'Provisioning retry queued.');
    }
}
