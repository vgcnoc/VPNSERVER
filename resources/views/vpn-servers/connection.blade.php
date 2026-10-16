@extends('layouts.console')

@section('title', 'Edit API connection')
@section('heading', 'Edit RouterOS API connection')

@section('content')
    <a href="{{ route('vpn-servers.index') }}" class="text-sm font-semibold text-violet-700 hover:underline">← Back to VPN Servers</a>
    <section class="mt-5 max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">{{ $server->name }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">Update the private RouterOS HTTPS REST address and the public customer VPN address. The API password is never displayed; leave it blank to keep the saved value.</p>
        <form method="POST" action="{{ route('vpn-servers.connection.update', $server) }}" class="mt-6 space-y-4">@csrf @method('PATCH')
            <label class="block text-sm font-semibold text-slate-800">HTTPS REST endpoint<input required name="endpoint" type="url" value="{{ old('endpoint', $server->endpoint) }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"><span class="mt-1 block text-xs font-normal text-slate-500">Example: https://10.20.30.2:8443 — do not append /rest.</span></label>
            <label class="block text-sm font-semibold text-slate-800">Client VPN endpoint<input required name="client_endpoint" value="{{ old('client_endpoint', $server->client_endpoint) }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"><span class="mt-1 block text-xs font-normal text-slate-500">Example: vpn.example.net: public hostname/IP shown to customers.</span></label>
            <label class="block text-sm font-semibold text-slate-800">API username<input required name="username" value="{{ old('username', $server->username) }}" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"></label>
            <label class="block text-sm font-semibold text-slate-800">New API password<input name="password" type="password" autocomplete="new-password" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500"><span class="mt-1 block text-xs font-normal text-slate-500">Leave empty to keep the existing encrypted password.</span></label>
            <button class="rounded-lg bg-violet-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700">Save API connection</button>
        </form>
    </section>
@endsection
