@extends('layouts.console')
@section('title', 'VPN')
@section('heading', 'VPN provisioning')
@section('content')
<a href="{{ route('dashboard') }}" class="text-violet-700 underline">Back to overview</a>
@if($errors->any())<p role="alert" class="my-4 text-red-700">{{ $errors->first() }}</p>@endif
<p class="my-4 max-w-3xl text-sm leading-6 text-slate-600">Choose the connection type offered by your VPN provider. WireGuard needs the public key from your device; never submit its private key. L2TP/IPsec, SSTP, and OpenVPN receive a dedicated RouterOS PPP account after provisioning.</p>
@foreach($subscriptions as $subscription)
<article class="mb-4 rounded-xl border bg-white p-5"><p class="break-all font-bold">{{ $subscription->id }}</p><p>Status: {{ $subscription->status }} · Expires: {{ $subscription->expires_at ?? 'Not started' }}</p>
@if($subscription->status === 'paid')
<form method="POST" action="{{ route('vpn.provision', $subscription->id) }}" class="mt-4 rounded-xl bg-slate-50 p-4">@csrf
    <label class="block text-sm font-semibold text-slate-800">Connection type<select name="protocol" data-vpn-protocol class="mt-1 block w-full rounded border-slate-300"><option value="wireguard">WireGuard</option><option value="l2tp_ipsec">L2TP/IPsec</option><option value="sstp">SSTP</option><option value="openvpn">OpenVPN (TCP)</option></select></label>
    <label data-wireguard-key class="mt-3 block text-sm font-semibold text-slate-800">WireGuard public key<input name="public_key" minlength="44" maxlength="44" class="mt-1 block w-full rounded border-slate-300 font-mono" placeholder="44-character Base64 public key"><span class="mt-1 block text-xs font-normal text-slate-500">Required only for WireGuard.</span></label>
    <button class="mt-3 rounded bg-violet-900 px-4 py-2 text-white">Create VPN connection</button>
</form>
@endif
</article>
@endforeach
@forelse($peers as $peer)
<article class="mb-4 rounded-xl border bg-white p-5"><p class="font-semibold">{{ strtoupper(str_replace('_', '/', $peer->protocol ?? 'wireguard')) }} · {{ $peer->address }}/32 · {{ $peer->status }}</p>
@if(($peer->protocol ?? 'wireguard') === 'wireguard')
<p>Handshake age reported by CHR: {{ $peer->last_handshake ?? 'Unavailable' }}</p><p>Checked: {{ $peer->checked_at ?? 'Not checked' }} · RX: {{ $peer->rx ?? 'Unavailable' }} · TX: {{ $peer->tx ?? 'Unavailable' }}</p>
@else
<div class="mt-3 rounded-lg bg-violet-50 p-3 text-sm text-violet-950"><p class="font-semibold">VPN client settings</p><p>Server: <span class="font-mono">{{ $peer->client_endpoint ?? 'Contact support' }}</span></p><p>Username: <span class="font-mono">{{ $peer->username }}</span></p><p>Password: <span class="font-mono">{{ $peer->connection_password ?? 'Unavailable' }}</span></p>@if(($peer->protocol ?? '') === 'l2tp_ipsec')<p>IPsec pre-shared key: <span class="font-mono">{{ $peer->ipsec_secret ?? 'Unavailable' }}</span></p>@endif<p class="mt-2 text-xs text-violet-800">Keep these credentials private and do not share screenshots.</p></div>
@endif
@if($peer->status === 'failed')<form method="POST" action="{{ route('vpn.retry', $peer->id) }}">@csrf<button class="mt-3 rounded bg-violet-900 px-4 py-2 text-white">Retry provisioning</button></form>@endif
</article>
@empty<p>No connections allocated.</p>@endforelse
<h2 class="my-4 text-xl font-bold">Job history</h2>
@foreach($jobs as $job)<p class="my-2 break-all text-sm">{{ $job->id }} · {{ $job->action }} · {{ $job->status }} · Attempt {{ $job->attempt }} · {{ $job->error_code }}</p>@endforeach
<script>
document.querySelectorAll('[data-vpn-protocol]').forEach((select) => {
    const form = select.closest('form'); const key = form.querySelector('[data-wireguard-key]'); const input = key.querySelector('input');
    const update = () => { const wireguard = select.value === 'wireguard'; key.hidden = !wireguard; input.required = wireguard; };
    select.addEventListener('change', update); update();
});
</script>
@endsection
