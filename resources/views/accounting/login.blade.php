@extends('accounting.layout')
@section('title', 'Staff sign in')
@section('narrow', true)
@section('lead')<p class="lead">Use the account your accounting administrator provided.</p>@endsection
@section('content')
<form action="{{ url('/login') }}" method="post">@csrf
<p><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></p>
<p><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></p>
<button class="block">Sign in</button></form>
@endsection
