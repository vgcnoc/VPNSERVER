<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionPeer;
use App\Services\VpnProvisioningService;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VpnController extends Controller
{
    public function index(Request $request, OrganizationContext $context)
    {
        abort_unless($context->id(), 403);
        $subscriptions = DB::table('subscriptions')->join('orders', 'orders.id', '=', 'subscriptions.order_id')
            ->where('subscriptions.organization_id', $context->id())->where('orders.customer_id', $request->user()->id)
            ->select('subscriptions.*')->get();
        $peers = DB::table('vpn_peers')->leftJoin('vpn_servers', 'vpn_servers.id', '=', 'vpn_peers.vpn_server_id')
            ->where('vpn_peers.organization_id', $context->id())->whereIn('vpn_peers.subscription_id', $subscriptions->pluck('id'))
            ->select('vpn_peers.*', 'vpn_servers.client_endpoint', 'vpn_servers.endpoint as management_endpoint', 'vpn_servers.protocol_secret')->get();
        $peers->each(function (object $peer): void {
            $peer->client_endpoint ??= parse_url((string) $peer->management_endpoint, PHP_URL_HOST);
            if (($peer->protocol ?? 'wireguard') !== 'wireguard' && $peer->connection_secret) {
                try {
                    $peer->connection_password = Crypt::decryptString($peer->connection_secret);
                } catch (\Throwable) {
                    $peer->connection_password = null;
                }
            }
            if (($peer->protocol ?? '') === 'l2tp_ipsec' && $peer->protocol_secret) {
                try {
                    $peer->ipsec_secret = Crypt::decryptString($peer->protocol_secret);
                } catch (\Throwable) {
                    $peer->ipsec_secret = null;
                }
            }
        });
        $jobs = DB::table('provisioning_jobs')->where('organization_id', $context->id())->whereIn('vpn_peer_id', $peers->pluck('id'))->latest('created_at')->limit(30)->get();
        return view('vpn.index', ['organization' => $context->organization(), 'subscriptions' => $subscriptions, 'peers' => $peers, 'jobs' => $jobs]);
    }

    public function provision(Request $request, string $subscription, OrganizationContext $context, VpnProvisioningService $service)
    {
        abort_unless($context->organization(), 403);
        $data = $request->validate([
            'protocol' => ['required', Rule::in(['wireguard', 'l2tp_ipsec', 'sstp', 'openvpn'])],
            'public_key' => ['nullable', 'string', 'size:44'],
        ]);
        try {
            $service->request($request->user(), $context->organization(), $subscription, $data['protocol'], $data['public_key'] ?? null);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->withErrors(['gateway' => 'Gateway verification failed. Contact the infrastructure administrator.']);
        }
        return back()->with('status', $data['protocol'] === 'wireguard' ? 'WireGuard provisioning queued. Keep the private key on your own device.' : 'VPN account provisioning queued. Your username and password appear below for this signed-in customer.');
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
