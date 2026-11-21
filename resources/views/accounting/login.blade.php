@extends('accounting.layout')
@section('title', 'Staff sign in')
@section('content')
<form action="{{ url('/login') }}" method="post">@csrf
<p><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required></p>
<p><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></p>
<button>Sign in</button></form>
@endsection
