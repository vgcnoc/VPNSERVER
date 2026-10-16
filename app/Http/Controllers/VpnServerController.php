<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Services\AuditService;
use App\Services\RouterOsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VpnServerController extends Controller
{
    public function index(): View
    {
        return view('vpn-servers.index', [
            'servers' => VpnServer::query()->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request, $audit) {
            $shouldBeDefault = ! VpnServer::query()->where('status', 'active')->exists();
            if ($shouldBeDefault) {
                VpnServer::query()->update(['is_default' => false]);
            }
            $server = VpnServer::query()->create($data + [
                'id' => (string) Str::uuid(),
                'status' => 'active',
                'is_default' => $shouldBeDefault,
            ]);
            $audit->record('vpn.server_created', $request->user(), $server, ['name' => $server->name, 'endpoint' => $server->endpoint]);
        });

        return back()->with('status', 'VPN server saved. Use Test connection before provisioning customers.');
    }

    public function makeDefault(VpnServer $server, Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($server->status === 'active', 422, 'Only active servers can be the default.');
        DB::transaction(function () use ($server, $request, $audit) {
            VpnServer::query()->update(['is_default' => false]);
            $server->update(['is_default' => true]);
            $audit->record('vpn.server_defaulted', $request->user(), $server, ['name' => $server->name]);
        });

        return back()->with('status', $server->name.' is now the default provisioning server.');
    }

    public function toggle(VpnServer $server, Request $request, AuditService $audit): RedirectResponse
    {
        $nextStatus = $server->status === 'active' ? 'disabled' : 'active';
        DB::transaction(function () use ($server, $nextStatus, $request, $audit) {
            $server->update(['status' => $nextStatus, 'is_default' => $nextStatus === 'disabled' ? false : $server->is_default]);
            if ($nextStatus === 'disabled' && ! VpnServer::query()->where('status', 'active')->where('id', '!=', $server->id)->exists()) {
                // No fallback is better than silently provisioning to a disabled gateway.
                VpnServer::query()->where('id', $server->id)->update(['is_default' => false]);
            }
            $audit->record('vpn.server_status_changed', $request->user(), $server, ['status' => $nextStatus]);
        });

        return back()->with('status', $server->name.' is '.$nextStatus.'.');
    }

    public function check(VpnServer $server, Request $request, RouterOsService $router, AuditService $audit): RedirectResponse
    {
        try {
            $router->health($server);
            $server->update(['health_status' => 'healthy', 'last_checked_at' => now(), 'last_error' => null]);
            $audit->record('vpn.server_health_checked', $request->user(), $server, ['result' => 'healthy']);
            return back()->with('status', $server->name.' passed the RouterOS and WireGuard health check.');
        } catch (\Throwable) {
            $server->update(['health_status' => 'unreachable', 'last_checked_at' => now(), 'last_error' => 'Connection or WireGuard verification failed.']);
            $audit->record('vpn.server_health_checked', $request->user(), $server, ['result' => 'unreachable']);
            return back()->withErrors(['server' => 'Connection test failed. Confirm HTTPS REST access, credentials, and WireGuard settings.']);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:vpn_servers,name'],
            'endpoint' => ['required', 'url:https', 'max:2048', 'unique:vpn_servers,endpoint'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'wireguard_interface' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/D'],
            'wireguard_public_key' => ['required', 'string', 'size:44'],
            'pool_prefix' => ['required', 'string', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D'],
            'capacity' => ['required', 'integer', 'between:1,253'],
        ]);

        abort_unless(filter_var($data['pool_prefix'].'.1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), 422, 'A valid IPv4 /24 pool prefix is required.');
        $key = base64_decode($data['wireguard_public_key'], true);
        abort_unless($key !== false && strlen($key) === 32 && base64_encode($key) === $data['wireguard_public_key'], 422, 'A canonical WireGuard server public key is required.');

        return $data;
    }
}
