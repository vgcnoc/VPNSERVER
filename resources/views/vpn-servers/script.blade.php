@extends('layouts.console')

@section('title', 'CHR Bootstrap Script')
@section('heading', 'Generate CHR bootstrap script')

@section('content')
    <a href="{{ route('vpn-servers.index') }}" class="text-sm font-semibold text-violet-700 hover:underline">← Back to VPN Servers</a>
    <section class="mt-5 max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">{{ $server->name }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">Paste this {{ strtoupper(str_replace('_', '/', $server->protocol)) }} script once in the RouterOS 7 terminal. It creates the dedicated service and pool for <span class="font-mono">{{ $server->pool_prefix }}.0/24</span>. It deliberately does not modify firewall, NAT, routing, certificate, or REST API settings.</p>

        @if($server->protocol === 'wireguard' && $hasOneTimePrivateKey)
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Copy this now.</strong> It contains the WireGuard private key and is shown only once. Do not save it in chat, screenshots, or source control.</div>
        @elseif($server->protocol === 'wireguard')
            <div class="mt-5 rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900">RouterOS will generate the WireGuard private/public key pair when you paste this script. Copy the public key printed at the end and save it below. The private key stays only on your CHR.</div>
        @endif
        @if($server->protocol === 'l2tp_ipsec')
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Sensitive script:</strong> this L2TP/IPsec script contains the pre-shared key. Copy it only to the intended CHR terminal and do not store it in screenshots or source control.</div>
        @elseif(in_array($server->protocol, ['sstp', 'openvpn'], true))
            <div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">The script references the existing RouterOS certificate <span class="font-mono">{{ $server->certificate_name }}</span>. Confirm that its private key is present and trusted before enabling the server.</div>
        @endif
        <textarea id="chr-script" readonly rows="18" class="mt-4 block w-full rounded-xl border-slate-300 bg-slate-950 p-4 font-mono text-xs leading-5 text-slate-100 shadow-sm">{{ $script }}</textarea>
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('chr-script').value).then(() => this.textContent = 'Copied')" class="mt-4 rounded-lg bg-violet-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700">Copy script</button>

        @if($server->protocol === 'wireguard' && ! $server->wireguard_public_key)
            <form method="POST" action="{{ route('vpn-servers.public-key', $server) }}" class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">@csrf
                <label class="block text-sm font-semibold text-slate-800">WireGuard public key printed by CHR<input name="wireguard_public_key" required minlength="44" maxlength="44" class="mt-2 block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500" placeholder="Paste the 44-character Base64 public key"></label>
                <button class="mt-3 rounded-lg border border-violet-200 bg-white px-4 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-50">Save public key</button>
            </form>
        @endif

        <ol class="mt-6 list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-600">
            <li>Paste the script into the CHR terminal and confirm the protocol service has been created with the dedicated pool.</li>
            <li>Configure HTTPS REST with a trusted certificate and restrictive firewall rules on a private management path.</li>
            <li>Return to <strong>VPN Servers</strong>, click <strong>Test connection</strong>, then activate the server. Only an active WireGuard server can currently be the automatic customer-provisioning default.</li>
        </ol>
    </section>
@endsection
