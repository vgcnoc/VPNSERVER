@extends('layouts.console')
@section('title', 'Organizations')
@section('heading', 'Organizations')
@section('content')

@if($isPlatformAdmin)
<p class="mb-5 text-sm text-slate-600">Platform-wide view of all registered organizations.</p>
@else
<p class="mb-5 text-sm text-slate-600">Organizations you are a member of. Switch your active tenant from the list below.</p>
@endif

@if($organizations->isEmpty())
<div class="rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm">
    <p class="text-lg font-semibold text-slate-700">No organizations found</p>
    <p class="mt-2 text-sm text-slate-500">You are not a member of any organization yet.</p>
</div>
@else
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach($organizations as $org)
    <article class="rounded-2xl border {{ $organization && $organization->id === $org->id ? 'border-violet-300 ring-2 ring-violet-200' : 'border-slate-200' }} bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-lg font-bold text-slate-900">{{ $org->name }}</h2>
                <p class="mt-1 text-xs text-slate-400">{{ $org->slug }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $org->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                {{ ucfirst($org->status) }}
            </span>
        </div>
        <dl class="mt-4 space-y-2 text-sm">
            <div class="flex items-center justify-between">
                <dt class="text-slate-500">Active members</dt>
                <dd class="font-semibold text-slate-900">{{ number_format($org->members_count) }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-slate-500">Timezone</dt>
                <dd class="font-medium text-slate-700">{{ $org->timezone ?? 'UTC' }}</dd>
            </div>
        </dl>
        @if(!$organization || $organization->id !== $org->id)
            @if($org->status === 'active')
            <form method="POST" action="{{ route('organizations.switch', $org) }}" class="mt-4">
                @csrf
                <button class="w-full rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-700 transition hover:bg-violet-100">
                    Switch to this organization
                </button>
            </form>
            @endif
        @else
        <div class="mt-4 rounded-lg bg-violet-50 px-3 py-2 text-center text-sm font-semibold text-violet-700">
            ✓ Currently active
        </div>
        @endif
    </article>
    @endforeach
</div>
<div class="mt-6">{{ $organizations->links() }}</div>
@endif
@endsection
