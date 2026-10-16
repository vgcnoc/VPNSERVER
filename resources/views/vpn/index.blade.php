@extends('layouts.console')
@section('title', 'VPN')
@section('heading', 'VPN provisioning')
@section('content')
<a href="{{ route('dashboard') }}" class="text-violet-700 underline">Back to overview</a>
@if($errors->any())<p role="alert" class="my-4 text-red-700">{{ $errors->first() }}</p>@endif
<p class="my-4 text-sm text-slate-600">Supply the WireGuard public key from your device. Never submit a private key. The initial release supports one peer per subscription on the configured CHR. A recent handshake confirms peer activity, not reachability of all destination networks.</p>
@foreach($subscriptions as $subscription)
<article class="mb-4 rounded-xl border bg-white p-5"><p class="break-all font-bold">{{ $subscription->id }}</p><p>Status: {{ $subscription->status }} · Expires: {{ $subscription->expires_at ?? 'Not started' }}</p>
@if($subscription->status === 'paid')
<form method="POST" action="{{ route('vpn.provision', $subscription->id) }}" class="mt-4">@csrf<label>Client public key<input name="public_key" minlength="44" maxlength="44" required class="block w-full rounded"></label><button class="mt-3 rounded bg-violet-900 px-4 py-2 text-white">Generate peer</button></form>
@endif
</article>
@endforeach
@forelse($peers as $peer)
<article class="mb-4 rounded-xl border bg-white p-5"><p>{{ $peer->address }}/32 · {{ $peer->status }}</p><p>Handshake age reported by CHR: {{ $peer->last_handshake ?? 'Unavailable' }}</p><p>Checked: {{ $peer->checked_at ?? 'Not checked' }} · RX: {{ $peer->rx ?? 'Unavailable' }} · TX: {{ $peer->tx ?? 'Unavailable' }}</p>
@if($peer->status === 'failed')<form method="POST" action="{{ route('vpn.retry', $peer->id) }}">@csrf<button class="mt-3 rounded bg-violet-900 px-4 py-2 text-white">Retry provisioning</button></form>@endif
</article>
@empty<p>No peers allocated.</p>@endforelse
<h2 class="my-4 text-xl font-bold">Job history</h2>
@foreach($jobs as $job)<p class="my-2 break-all text-sm">{{ $job->id }} · {{ $job->action }} · {{ $job->status }} · Attempt {{ $job->attempt }} · {{ $job->error_code }}</p>@endforeach
@endsection
