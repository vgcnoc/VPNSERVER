@extends('layouts.console')

@section('title', 'Automation')
@section('heading', 'Automation & health')

@section('content')
    <p class="max-w-3xl text-sm leading-6 text-slate-600">Monitor the background workflows that reconcile payments and verify WireGuard peers. Automated jobs only change a subscription after provider or gateway verification succeeds.</p>

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">RouterOS gateway</p><p @class(['mt-2 text-xl font-bold', $routerConfigured ? 'text-emerald-700' : 'text-amber-700'])>{{ $routerConfigured ? 'Configured' : 'Awaiting setup' }}</p><p class="mt-2 text-xs text-slate-400">Provisioning remains protected until verified.</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Midtrans Sandbox</p><p @class(['mt-2 text-xl font-bold', $paymentConfigured ? 'text-emerald-700' : 'text-amber-700'])>{{ $paymentConfigured ? 'Configured' : 'Awaiting key' }}</p><p class="mt-2 text-xs text-slate-400">Webhook signatures are checked server-side.</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Queued provisioning</p><p class="mt-2 text-3xl font-bold text-slate-950">{{ number_format($queuedJobs) }}</p><p class="mt-2 text-xs text-slate-400">Awaiting secure gateway processing.</p></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">Failed jobs</p><p @class(['mt-2 text-3xl font-bold', $failedJobs ? 'text-red-700' : 'text-emerald-700'])>{{ number_format($failedJobs) }}</p><p class="mt-2 text-xs text-slate-400">Retry from the VPN page after review.</p></article>
    </section>

    <section class="mt-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-bold text-slate-900">Recent provisioning activity</h2><p class="mt-1 text-xs text-slate-500">VPN reconciliation is scheduled every minute when the Laravel scheduler is running.</p></div>
        @forelse($recentJobs as $job)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 last:border-0"><div><p class="font-mono text-xs text-slate-500">{{ $job->id }}</p><p class="mt-1 text-sm font-semibold text-slate-800">{{ ucfirst($job->action) }} · {{ ucfirst($job->status) }}</p></div><p class="text-xs text-slate-500">Attempt {{ $job->attempt }} · {{ $job->created_at }}</p></div>
        @empty
            <div class="p-8 text-center text-sm text-slate-500">No provisioning jobs for this organization yet.</div>
        @endforelse
    </section>
@endsection
