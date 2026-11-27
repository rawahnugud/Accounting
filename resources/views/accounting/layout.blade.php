<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title') · Midnight Travel</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f8f6f3;color:#281e22;font:1rem/1.55 system-ui,sans-serif}
header,main{max-width:46rem;margin:auto;padding:1.5rem}header{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #d9cfd2}
header strong,a{color:#651c39}h1{line-height:1.15}form p{margin:1rem 0}label{display:block;font-weight:600}
input,select,button{font:inherit;padding:.55rem .8rem}input,select{width:100%;max-width:30rem;border:1px solid #a89ba0;border-radius:.3rem}
button{border:0;background:#651c39;color:white;border-radius:.3rem;cursor:pointer}li{padding:.3rem 0}
[role=alert]{color:#82112b}
</style></head><body><header><strong>Midnight Travel · Accounting</strong>
@auth<form action="{{ url('/logout') }}" method="post">@csrf<button>Sign out</button></form>@endauth
</header><main><h1>@yield('title')</h1>
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@if(session('message'))<p role="status">{{ session('message') }}</p>@endif
@yield('content')</main></body></html>
