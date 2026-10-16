@extends('layouts.console')

@section('title', 'VPN Servers')
@section('heading', 'VPN server management')

@section('content')
    <p class="max-w-3xl text-sm leading-6 text-slate-600">Register each dedicated RouterOS 7 WireGuard gateway here. Credentials are encrypted at rest and never shown after saving. The default active server receives new VPN peers.</p>

    @if($errors->any())
        <div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('vpn-servers.store') }}" class="mt-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2">
        @csrf
        <div class="md:col-span-2"><h2 class="text-lg font-bold text-slate-900">Add RouterOS gateway</h2><p class="mt-1 text-xs text-slate-500">Use a restricted HTTPS REST management endpoint. Never expose the router REST API publicly.</p></div>
        <label class="text-sm font-medium text-slate-700">Server name<input required name="name" value="{{ old('name') }}" maxlength="120" placeholder="Jakarta CHR 01" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">HTTPS endpoint<input required name="endpoint" value="{{ old('endpoint') }}" type="url" placeholder="https://chr-01.example.net" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">API username<input required name="username" value="{{ old('username') }}" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">API password<input required name="password" type="password" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">WireGuard interface<input required name="wireguard_interface" value="{{ old('wireguard_interface', 'wg-vpnrent') }}" maxlength="64" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">WireGuard server public key<input required name="wireguard_public_key" value="{{ old('wireguard_public_key') }}" minlength="44" maxlength="44" placeholder="Base64 public key" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">Dedicated pool prefix<input required name="pool_prefix" value="{{ old('pool_prefix') }}" placeholder="10.80.0" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <label class="text-sm font-medium text-slate-700">Peer capacity<input required name="capacity" type="number" min="1" max="253" value="{{ old('capacity', 100) }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
        <div class="md:col-span-2"><button class="rounded-lg bg-violet-800 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700">Save VPN server</button></div>
    </form>

    <section class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-bold text-slate-900">Registered servers</h2><p class="mt-1 text-xs text-slate-500">Use Test connection before selecting a new default server.</p></div>
        @forelse($servers as $server)
            <article class="border-b border-slate-100 px-5 py-5 last:border-0">
                <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start"><div><div class="flex flex-wrap items-center gap-2"><h3 class="text-base font-bold text-slate-900">{{ $server->name }}</h3>@if($server->is_default)<span class="rounded-full bg-violet-100 px-2.5 py-1 text-xs font-semibold text-violet-700">Default</span>@endif<span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', $server->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'])>{{ ucfirst($server->status) }}</span><span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', $server->health_status === 'healthy' ? 'bg-emerald-50 text-emerald-700' : ($server->health_status === 'unreachable' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700')])>Health: {{ $server->health_status }}</span></div><p class="mt-2 break-all font-mono text-xs text-slate-500">{{ $server->endpoint }}</p><p class="mt-2 text-sm text-slate-600">{{ $server->wireguard_interface }} · {{ $server->pool_prefix }}.2–.254 · {{ $server->capacity }} peers</p>@if($server->last_checked_at)<p class="mt-1 text-xs text-slate-400">Last checked {{ $server->last_checked_at->diffForHumans() }}</p>@endif</div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('vpn-servers.check', $server) }}">@csrf<button class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-100">Test connection</button></form>
                    @unless($server->is_default)<form method="POST" action="{{ route('vpn-servers.default', $server) }}">@csrf<button @disabled($server->status !== 'active') class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Set default</button></form>@endunless
                    <form method="POST" action="{{ route('vpn-servers.toggle', $server) }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">{{ $server->status === 'active' ? 'Disable' : 'Enable' }}</button></form>
                </div></div>
            </article>
        @empty
            <div class="p-8 text-center text-sm text-slate-500">No VPN server has been registered. Add a dedicated RouterOS 7 gateway above.</div>
        @endforelse
    </section>
@endsection
