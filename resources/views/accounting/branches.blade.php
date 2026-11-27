@extends('accounting.layout')
@section('title', 'Your branches')
@section('lead')<p class="lead">Choose a branch to see its bank and safe accounts.</p>@endsection
@section('content')
<ul class="grid">@forelse($branches as $branch)
<li><a class="card tile" href="{{ url('/branches/'.$branch->id) }}"><small>{{ $branch->company }}</small><strong>{{ $branch->name }}</strong><span class="chip">{{ ucfirst($branch->role) }}</span></a></li>
@empty<li class="card empty">No branch assigned. Contact your accounting administrator.</li>@endforelse</ul>
@endsection
