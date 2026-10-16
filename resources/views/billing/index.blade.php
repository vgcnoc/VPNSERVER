@extends('layouts.console')
@section('title', 'Billing')
@section('heading', 'Packages & billing')
@section('content')
<a href="{{ route('dashboard') }}" class="text-violet-700 underline">Back to overview</a>
@if($errors->any())
<div role="alert" class="my-4 rounded-lg bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>
@endif
<p class="my-4 text-sm text-slate-600">Payments require manual verification by an authorized staff member. Payment alone does not activate a VPN.</p>
@if(auth()->user()->hasRole('super-admin'))
<form method="POST" action="{{ route('billing.plans') }}" class="my-6 grid gap-3 rounded-xl border bg-white p-5 sm:grid-cols-2">
@csrf
<h2 class="font-bold sm:col-span-2">Create package</h2>
<label>Name <input name="name" required maxlength="120" class="block w-full rounded border-slate-300"></label>
<label>Price (IDR) <input name="price_idr" type="number" min="1" max="1000000000" required class="block w-full rounded border-slate-300"></label>
<label>Duration (days) <input name="duration_days" type="number" min="1" max="3660" required class="block w-full rounded border-slate-300"></label>
<label>Peer limit <input name="peer_limit" type="number" min="1" max="1000" value="1" required class="block w-full rounded border-slate-300"></label>
<button class="rounded bg-violet-900 px-4 py-2 text-white">Save package</button>
</form>
@endif
<section class="my-6 grid gap-4 sm:grid-cols-3">
@forelse($plans as $plan)
<article class="rounded-xl border bg-white p-5"><h2 class="font-bold">{{ $plan->name }}</h2><p class="my-3 text-xl">Rp {{ number_format($plan->price_idr, 0, ',', '.') }}</p><p class="text-sm text-slate-500">{{ $plan->duration_days }} days · {{ $plan->peer_limit }} peers</p>
<form method="POST" action="{{ route('billing.orders') }}" class="mt-4">@csrf<input type="hidden" name="plan_id" value="{{ $plan->id }}"><input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><button class="rounded bg-violet-900 px-4 py-2 text-white">Create order</button></form></article>
@empty<p>No packages available yet.</p>@endforelse
</section>
<h2 class="mb-3 text-xl font-bold">Invoices</h2>
@forelse($invoices as $invoice)
<article class="mb-4 rounded-xl border bg-white p-5"><h3 class="font-bold">{{ $invoice->description }}</h3><p class="break-all text-xs text-slate-500">{{ $invoice->id }}</p><p class="my-2">Rp {{ number_format($invoice->amount_idr, 0, ',', '.') }} · {{ $invoice->status }}</p><p class="text-sm">Subscription: {{ $invoice->subscription_status }} · Due: {{ $invoice->due_at }} UTC</p>
@if($canManage && $invoice->status === 'unpaid' && $invoice->customer_id !== auth()->id())
<form method="POST" action="{{ route('billing.approve', $invoice->id) }}" class="mt-4 flex flex-wrap items-center gap-3">@csrf<label>Bank transaction reference<input name="reference" required maxlength="100" class="block rounded border-slate-300"></label><label>Received amount (IDR)<input name="amount_idr" type="number" min="1" required class="block rounded border-slate-300"></label><label class="text-sm"><input type="checkbox" name="confirmed" value="1" required> I checked the bank record.</label><button class="rounded bg-violet-900 px-4 py-2 text-white">Verify payment</button></form>
@endif
@if($invoice->status === 'unpaid' && $invoice->customer_id === auth()->id())
<form method="POST" action="{{ route('billing.checkout', $invoice->id) }}" class="mt-4">@csrf<button class="rounded bg-violet-900 px-4 py-2 text-white">Pay with Midtrans (sandbox)</button></form>
@endif
</article>
@empty<p>No invoices yet.</p>@endforelse
{{ $invoices->links() }}
@endsection
