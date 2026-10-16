@extends('layouts.console')
@section('title', 'Resellers')
@section('heading', 'Reseller accounts & commissions')
@section('content')
<a href="{{ route('dashboard') }}" class="text-violet-700 underline">Back to overview</a>
@if($errors->any())<p role="alert" class="my-4 text-red-700">{{ $errors->first() }}</p>@endif
<form method="POST" action="{{ route('resellers.register') }}" class="my-4">@csrf<button class="rounded bg-violet-900 px-4 py-2 text-white">Apply for reseller account</button></form>
@foreach($accounts as $account)
<article class="my-4 rounded-xl border bg-white p-5"><p class="break-all">{{ $account->id }} · User #{{ $account->user_id }}</p><p>{{ $account->status }} · {{ $account->commission_bps / 100 }}% commission</p>
@if($admin)
<form method="POST" action="{{ route('resellers.update', $account->id) }}" class="mt-4 flex flex-wrap gap-3">@csrf<label>Status<select name="status" class="block rounded"><option value="approved">Approved</option><option value="suspended">Suspended</option></select></label><label>Commission (basis points)<input type="number" name="commission_bps" min="0" max="10000" value="{{ $account->commission_bps }}" required class="block rounded"></label><button class="rounded bg-violet-900 px-4 py-2 text-white">Update</button></form>
<form method="POST" action="{{ route('resellers.customer', $account->id) }}" class="mt-4 flex flex-wrap gap-3">@csrf<label>Customer user ID<input type="number" name="customer_id" min="1" required class="block rounded"></label><button class="rounded bg-violet-900 px-4 py-2 text-white">Assign customer</button></form>
@endif
</article>
@endforeach
<h2 class="my-4 text-xl font-bold">Commission ledger</h2>
<p class="mb-4 text-sm text-slate-600">Accrued commissions are accounting records. Withdrawals and payouts are not enabled. Midtrans sandbox payments are test transactions.</p>
@forelse($commissions as $commission)
<div class="mb-3 rounded border bg-white p-4"><p>Rp {{ number_format($commission->amount_idr, 0, ',', '.') }} · {{ $commission->status }}</p><p class="break-all text-xs text-slate-500">Payment {{ $commission->payment_id }} · {{ $commission->created_at }} UTC</p></div>
@empty<p>No commissions yet.</p>@endforelse
{{ $commissions->links() }}
@endsection
