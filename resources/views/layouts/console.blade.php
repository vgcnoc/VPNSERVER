<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Console') · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 font-sans text-slate-900">
    <div class="min-h-screen lg:grid lg:grid-cols-[17rem_1fr]">
        <aside class="hidden bg-[#1b1037] px-5 py-6 text-violet-100 lg:flex lg:flex-col">
            <a href="{{ route('dashboard') }}" class="mb-10 flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-500 text-lg font-bold text-white shadow-lg shadow-violet-950/40">V</span><span><span class="block text-base font-bold tracking-wide text-white">VPNRENT</span><span class="block text-[10px] font-semibold tracking-[0.24em] text-violet-300">ENTERPRISE</span></span></a>
            <nav class="space-y-1 text-sm">
                @php($navActive = 'bg-violet-500/20 font-semibold text-white ring-1 ring-inset ring-violet-400/20')
                @php($navIdle = 'text-violet-200 transition hover:bg-white/10 hover:text-white')
                <a href="{{ route('dashboard') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('dashboard') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center rounded-md bg-violet-400/20">⌁</span> Overview</a>
                <a href="{{ route('organizations.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('organizations.*') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center">◈</span> Organizations</a>
                <a href="{{ route('vpn.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('vpn.*') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center">⌁</span> VPN Infrastructure</a>
                @if(auth()->user()->hasRole('super-admin'))
                    <a href="{{ route('vpn-servers.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('vpn-servers.*') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center">◫</span> VPN Servers</a>
                @endif
                <a href="{{ route('billing.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('billing.*') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center">¤</span> Billing</a>
                <a href="{{ route('resellers.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('resellers.*') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center">◉</span> Resellers</a>
                <a href="{{ route('automation.index') }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2.5', request()->routeIs('automation.*') ? $navActive : $navIdle])><span class="grid h-6 w-6 place-items-center">□</span> Automation</a>
            </nav>
            <div class="mt-auto rounded-xl border border-violet-400/20 bg-white/5 p-4"><p class="text-xs font-semibold uppercase tracking-[0.14em] text-violet-300">Operations console</p><p class="mt-2 text-sm font-medium text-white">Billing, VPN & reseller workflows</p><p class="mt-1 text-xs leading-5 text-violet-200/75">Gateway actions remain protected until RouterOS is configured and verified.</p></div>
        </aside>
        <div class="min-w-0"><header class="border-b border-slate-200 bg-white px-5 py-4 sm:px-8"><div class="mx-auto flex max-w-7xl items-center justify-between gap-4"><div class="min-w-0"><p class="truncate text-xs font-semibold uppercase tracking-[0.16em] text-violet-700">{{ $organization?->name ?? 'No active organization' }}</p><h1 class="mt-1 text-xl font-bold tracking-tight text-slate-950">@yield('heading', 'Overview')</h1></div><div class="flex items-center gap-3"><a href="{{ route('profile.edit') }}" class="hidden rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-violet-200 hover:text-violet-700 sm:inline">Profile</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg bg-[#2b164f] px-3 py-2 text-sm font-semibold text-white transition hover:bg-violet-800">Sign out</button></form></div></div></header><main class="mx-auto max-w-7xl px-5 py-7 sm:px-8">@if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif @yield('content')</main></div>
    </div>
    @stack('scripts')
</body>
</html>
