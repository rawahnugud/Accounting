@extends('accounting.layout')
@section('title', 'Your branches')
@section('content')
<ul>@forelse($branches as $branch)
<li><a href="{{ url('/branches/'.$branch->id) }}">{{ $branch->company }} · {{ $branch->name }}</a> ({{ $branch->role }})</li>
@empty<li>No branch assigned. Contact your accounting administrator.</li>@endforelse</ul>
@endsection
