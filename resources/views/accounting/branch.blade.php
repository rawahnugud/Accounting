@extends('accounting.layout')
@section('title', $assignment->name)
@section('content')
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
@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old($field) == $account->id)>{{ $account->name }} ({{ $account->kind }})</option>@endforeach
</select></p>@endforeach
<p><label for="amount">Amount ({{ $assignment->reporting_currency }})</label>
<input id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required></p>
<button>Record transfer</button></form>
@elseif($assignment->role !== 'cashier')
<p>You have view-only access. Ask your accounting administrator if you need to record transfers.</p>
@else
<p>Transfers need at least two {{ $assignment->reporting_currency }} accounts in this branch.</p>
@endif
<h2>Recent transfers</h2>
@if($transfers->isEmpty())<p>No transfers recorded yet.</p>@else
<table><thead><tr><th>#</th><th>Date (UTC)</th><th>From</th><th>To</th><th>Amount</th><th>By</th></tr></thead><tbody>
@foreach($transfers as $t)<tr><td>{{ $t->id }}</td><td>{{ $t->posted_at }}</td><td>{{ $t->source }}</td><td>{{ $t->destination }}</td><td>{{ $t->amount }} {{ $t->currency }}</td><td>{{ $t->actor }}</td></tr>@endforeach
</tbody></table>@endif
@endsection
