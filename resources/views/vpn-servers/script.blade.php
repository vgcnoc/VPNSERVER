@extends('layouts.console')

@section('title', 'CHR Bootstrap Script')
@section('heading', 'Generate CHR bootstrap script')

@section('content')
    <a href="{{ route('vpn-servers.index') }}" class="text-sm font-semibold text-violet-700 hover:underline">← Back to VPN Servers</a>
    <section class="mt-5 max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">{{ $server->name }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">Paste this script once in the RouterOS 7 terminal. It creates a new WireGuard interface and the gateway address for <span class="font-mono">{{ $server->pool_prefix }}.0/24</span>. It deliberately does not modify firewall, NAT, routing, certificate, or REST API settings.</p>

        @if($hasOneTimePrivateKey)
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Copy this now.</strong> It contains the WireGuard private key and is shown only once. Do not save it in chat, screenshots, or source control.</div>
        @else
            <div class="mt-5 rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900">RouterOS will generate the WireGuard private/public key pair when you paste this script. Copy the public key printed at the end and save it below. The private key stays only on your CHR.</div>
        @endif
        <textarea id="chr-script" readonly rows="18" class="mt-4 block w-full rounded-xl border-slate-300 bg-slate-950 p-4 font-mono text-xs leading-5 text-slate-100 shadow-sm">{{ $script }}</textarea>
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('chr-script').value).then(() => this.textContent = 'Copied')" class="mt-4 rounded-lg bg-violet-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700">Copy script</button>

        @if(! $server->wireguard_public_key)
            <form method="POST" action="{{ route('vpn-servers.public-key', $server) }}" class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">@csrf
                <label class="block text-sm font-semibold text-slate-800">WireGuard public key printed by CHR<input name="wireguard_public_key" required minlength="44" maxlength="44" class="mt-2 block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500" placeholder="Paste the 44-character Base64 public key"></label>
                <button class="mt-3 rounded-lg border border-violet-200 bg-white px-4 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-50">Save public key</button>
            </form>
        @endif

        <ol class="mt-6 list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-600">
            <li>Paste the script into the CHR terminal and confirm it prints the same public key stored by this server record.</li>
            <li>Configure HTTPS REST with a trusted certificate and restrictive firewall rules on a private management path.</li>
            <li>Return to <strong>VPN Servers</strong>, click <strong>Test connection</strong>, activate the server, then set it as the default.</li>
        </ol>
    </section>
@endsection
