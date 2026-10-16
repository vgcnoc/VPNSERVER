<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Services\AuditService;
use App\Services\RouterOsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        [$privateKey, $publicKey] = $data['protocol'] === 'wireguard'
            ? $this->wireGuardKeypair()
            : [null, null];

        $server = DB::transaction(function () use ($data, $request, $audit, $publicKey) {
            $server = VpnServer::query()->create($data + [
                'id' => (string) Str::uuid(),
                'wireguard_public_key' => $publicKey,
                'status' => 'draft',
                'is_default' => false,
            ]);
            $audit->record('vpn.server_created', $request->user(), $server, ['name' => $server->name, 'endpoint' => $server->endpoint]);

            return $server;
        });
        if ($privateKey) {
            $request->session()->flash('vpn_server_bootstrap', ['server_id' => $server->id, 'private_key' => $privateKey]);
        }

        return to_route('vpn-servers.script', $server)->with('status', 'Server draft saved. Generate and paste the CHR bootstrap script before activating this server.');
    }

    public function script(VpnServer $server, Request $request): View
    {
        $bootstrap = $request->session()->get('vpn_server_bootstrap');
        $privateKey = is_array($bootstrap) && ($bootstrap['server_id'] ?? null) === $server->id ? ($bootstrap['private_key'] ?? null) : null;

        return view('vpn-servers.script', [
            'server' => $server,
            'script' => $this->bootstrapScript($server, is_string($privateKey) ? $privateKey : null),
            'hasOneTimePrivateKey' => is_string($privateKey),
        ]);
    }

    public function regenerateScript(VpnServer $server, Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($server->protocol === 'wireguard', 422, 'Only WireGuard servers use key regeneration.');
        abort_if(DB::table('vpn_peers')->where('vpn_server_id', $server->id)->exists(), 409, 'Bootstrap keys cannot be rotated while the server has allocated peers.');
        [$privateKey, $publicKey] = $this->wireGuardKeypair();
        abort_unless($privateKey && $publicKey, 503, 'This PHP installation cannot generate a WireGuard key pair. Create the interface with the script and submit its public key instead.');
        $server->update(['wireguard_public_key' => $publicKey, 'health_status' => 'unknown', 'last_checked_at' => null, 'last_error' => null]);
        $audit->record('vpn.server_bootstrap_regenerated', $request->user(), $server, ['name' => $server->name]);
        $request->session()->flash('vpn_server_bootstrap', ['server_id' => $server->id, 'private_key' => $privateKey]);

        return to_route('vpn-servers.script', $server)->with('status', 'New bootstrap script generated. Replace the CHR interface only if it has not been used by customers.');
    }

    public function setPublicKey(VpnServer $server, Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($server->protocol === 'wireguard', 422, 'Only WireGuard servers have a WireGuard public key.');
        $data = $request->validate(['wireguard_public_key' => ['required', 'string', 'size:44']]);
        $key = base64_decode($data['wireguard_public_key'], true);
        abort_unless($key !== false && strlen($key) === 32 && base64_encode($key) === $data['wireguard_public_key'], 422, 'A canonical WireGuard public key is required.');
        $server->update(['wireguard_public_key' => $data['wireguard_public_key'], 'health_status' => 'unknown', 'last_checked_at' => null, 'last_error' => null]);
        $audit->record('vpn.server_public_key_saved', $request->user(), $server, ['name' => $server->name]);

        return to_route('vpn-servers.index')->with('status', 'WireGuard public key saved. Test the connection, activate the server, then set it as default.');
    }

    public function makeDefault(VpnServer $server, Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($server->protocol === 'wireguard', 422, 'Automatic subscription provisioning currently requires a WireGuard server.');
        abort_unless($server->status === 'active', 422, 'Only active servers can be the default.');
        abort_unless($server->wireguard_public_key, 422, 'Save the WireGuard public key before selecting a default server.');
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
        abort_if($nextStatus === 'active' && $server->protocol === 'wireguard' && ! $server->wireguard_public_key, 422, 'Generate the CHR script and save the WireGuard public key before activating this server.');
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
        abort_if($server->protocol === 'wireguard' && ! $server->wireguard_public_key, 422, 'Save the WireGuard public key before testing this server.');
        try {
            $router->health($server);
            $server->update(['health_status' => 'healthy', 'last_checked_at' => now(), 'last_error' => null]);
            $audit->record('vpn.server_health_checked', $request->user(), $server, ['result' => 'healthy']);
            return back()->with('status', $server->name.' passed the RouterOS health check.');
        } catch (\Throwable) {
            $server->update(['health_status' => 'unreachable', 'last_checked_at' => now(), 'last_error' => 'Connection or protocol service verification failed.']);
            $audit->record('vpn.server_health_checked', $request->user(), $server, ['result' => 'unreachable']);
            return back()->withErrors(['server' => 'Connection test failed. Confirm HTTPS REST access, credentials, and the protocol service settings.']);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:vpn_servers,name'],
            'protocol' => ['required', Rule::in(['wireguard', 'l2tp_ipsec', 'sstp', 'openvpn'])],
            'endpoint' => ['required', 'url:https', 'max:2048', 'unique:vpn_servers,endpoint'],
            'client_endpoint' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'protocol_secret' => ['nullable', 'string', 'max:4096'],
            'certificate_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.-]+$/D'],
            'wireguard_interface' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/D'],
            'pool_prefix' => ['required', 'string', 'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D'],
            'capacity' => ['required', 'integer', 'between:1,253'],
        ]);

        abort_unless(filter_var($data['pool_prefix'].'.1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), 422, 'A valid IPv4 /24 pool prefix is required.');
        abort_if($data['protocol'] === 'l2tp_ipsec' && ! filled($data['protocol_secret'] ?? null), 422, 'An IPsec pre-shared key is required for L2TP/IPsec.');
        abort_if(in_array($data['protocol'], ['sstp', 'openvpn'], true) && ! filled($data['certificate_name'] ?? null), 422, 'A RouterOS certificate name is required for SSTP and OpenVPN.');
        return $data;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function wireGuardKeypair(): array
    {
        if (! function_exists('sodium_crypto_scalarmult_base')) {
            return [null, null];
        }
        $privateKey = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);

        return [base64_encode($privateKey), base64_encode(sodium_crypto_scalarmult_base($privateKey))];
    }

    private function bootstrapScript(VpnServer $server, ?string $privateKey): string
    {
        return match ($server->protocol) {
            'l2tp_ipsec' => $this->pppBootstrapScript($server, 'l2tp_ipsec'),
            'sstp' => $this->pppBootstrapScript($server, 'sstp'),
            'openvpn' => $this->pppBootstrapScript($server, 'openvpn'),
            default => $this->wireGuardBootstrapScript($server, $privateKey),
        };
    }

    private function wireGuardBootstrapScript(VpnServer $server, ?string $privateKey): string
    {
        $quote = static fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $interface = $quote($server->wireguard_interface);
        $address = $quote($server->pool_prefix.'.1/24');
        $private = $privateKey ? ' private-key="'.$quote($privateKey).'"' : '';
        $name = $quote($server->name);

        return <<<ROS
# VPNRENT CHR bootstrap for {$name}
# Paste once on RouterOS 7. This does not create firewall, NAT, or REST API rules.
:local wgName "{$interface}"
:local gatewayAddress "{$address}"

:if ([:len [/interface/wireguard find where name=\$wgName]] > 0) do={
    :error "WireGuard interface already exists. Do not overwrite an existing customer gateway."
}
/interface/wireguard add name=\$wgName{$private} listen-port=13231 mtu=1420 comment="VPNRENT managed gateway"

:if ([:len [/ip/address find where address=\$gatewayAddress]] = 0) do={
    /ip/address add address=\$gatewayAddress interface=\$wgName comment="VPNRENT WireGuard gateway"
}

:put ("VPNRENT public key: " . [/interface/wireguard get [find where name=\$wgName] public-key])
# Next: securely enable HTTPS REST with a trusted certificate and restricted firewall,
# then return to VPNRENT and use Test connection before setting this server active/default.
ROS;
    }

    private function pppBootstrapScript(VpnServer $server, string $protocol): string
    {
        $quote = static fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $slug = substr(str_replace('-', '', $server->id), 0, 8);
        $pool = 'vpnrent-'.$protocol.'-'.$slug;
        $profile = 'vpnrent-'.$protocol.'-'.$slug;
        $address = $server->pool_prefix.'.1';
        $name = $quote($server->name);
        $serverSetup = match ($protocol) {
            'l2tp_ipsec' => "/interface l2tp-server server set enabled=yes use-ipsec=required ipsec-secret=\"{$quote((string) $server->protocol_secret)}\" default-profile=\$profileName authentication=mschap2",
            'sstp' => "/interface sstp-server server set enabled=yes default-profile=\$profileName certificate=\"{$quote((string) $server->certificate_name)}\" authentication=mschap2 tls-version=only-1.2",
            default => ":if ([:len [/interface/ovpn-server/server find where name=\"vpnrent-{$slug}\"]] = 0) do={ /interface/ovpn-server/server add name=\"vpnrent-{$slug}\" disabled=no protocol=tcp port=1194 certificate=\"{$quote((string) $server->certificate_name)}\" auth=sha256 cipher=aes256-gcm require-client-certificate=no default-profile=\$profileName }",
        };

        return <<<ROS
# VPNRENT {$protocol} bootstrap for {$name}
# Paste once on RouterOS 7. Firewall, NAT, routes, and certificates are intentionally not changed.
:local poolName "{$pool}"
:local profileName "{$profile}"
:local gatewayAddress "{$address}"
:local poolRange "{$server->pool_prefix}.2-{$server->pool_prefix}.254"

:if ([:len [/ip/pool find where name=\$poolName]] = 0) do={
    /ip/pool add name=\$poolName ranges=\$poolRange comment="VPNRENT {$protocol} pool"
}
:if ([:len [/ppp/profile find where name=\$profileName]] = 0) do={
    /ppp/profile add name=\$profileName local-address=\$gatewayAddress remote-address=\$poolName use-encryption=required only-one=yes
}
{$serverSetup}
:put "VPNRENT {$protocol} server enabled. Add customer PPP secrets through the approved provisioning workflow."
ROS;
    }
}
