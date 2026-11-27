@extends('accounting.layout')
@section('title', $assignment->name)
@section('content')
<a href="{{ route('home') }}">Your branches</a>
<h2>Bank and safe accounts</h2>
<p>{{ $assignment->company }} · {{ $assignment->reporting_currency }}</p>
<ul>@forelse($accounts as $account)<li>{{ $account->name }} ({{ $account->kind }})</li>@empty<li>No accounts in this currency.</li>@endforelse</ul>
@if($assignment->role === 'cashier' && $accounts->count() >= 2)
<h2>Record a transfer</h2>
<form method="post" action="{{ url('/branches/'.$assignment->id.'/transfers') }}">@csrf
<input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}">
@foreach(['source'=>'From account','destination'=>'To account'] as $field=>$label)
<p><label for="{{ $field }}">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}" required>
<option value="">Select an account</option>
@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old($field) == $account->id)>{{ $account->name }}</option>@endforeach
</select></p>@endforeach
<p><label for="amount">Amount ({{ $assignment->reporting_currency }})</label>
<input id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required></p>
<button>Record transfer</button></form>
@endif
@endsection
