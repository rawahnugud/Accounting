@extends('accounting.layout')
@section('title', $assignment->name)
@section('before')<a class="back" href="{{ route('home') }}">← Your branches</a>@endsection
@section('lead')<ul class="chips"><li class="chip plain">{{ $assignment->company }}</li><li class="chip plain">{{ $assignment->reporting_currency }}</li><li class="chip">{{ ucfirst($assignment->role) }}</li></ul>@endsection
@section('content')
<h2>Bank and safe accounts</h2>
<ul class="grid">@forelse($accounts as $account)
<li class="card tile"><small>{{ $account->kind === 'bank' ? 'Bank account' : 'Safe' }}</small><strong>{{ $account->name }}</strong></li>
@empty<li class="card empty">No accounts in this currency.</li>@endforelse</ul>
@if($assignment->role === 'cashier' && $accounts->count() >= 2)
<h2>Record a transfer</h2>
<form class="card" method="post" action="{{ url('/branches/'.$assignment->id.'/transfers') }}">@csrf
<input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}">
<div class="pair">@foreach(['source'=>'From account','destination'=>'To account'] as $field=>$label)
<p><label for="{{ $field }}">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}" required>
<option value="">Select an account</option>
@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old($field) == $account->id)>{{ $account->name }}</option>@endforeach
</select></p>@endforeach</div>
<p><label for="amount">Amount</label>
<span class="amount"><input id="amount" name="amount" inputmode="decimal" placeholder="0.00" value="{{ old('amount') }}" required><span aria-hidden="true">{{ $assignment->reporting_currency }}</span></span></p>
<button>Record transfer</button></form>
@elseif($assignment->role === 'viewer')
<p class="lead" style="margin-top:1.5rem">You have view-only access to this branch.</p>
@endif
@endsection
