@php($mark = '<svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#651c39"/><path d="M19.5 7.5a9 9 0 1 0 5 15.6A10 10 0 0 1 19.5 7.5Z" fill="#f8f6f3"/><circle cx="23" cy="10" r="1.3" fill="#f8f6f3"/></svg>')
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title') · Midnight Travel</title>
<link rel="icon" href="data:image/svg+xml,{{ rawurlencode($mark) }}">
<style>
:root{--brand:#651c39;--brand-dark:#4c142a;--brand-soft:#f3e7ec;--bg:#f8f6f3;--surface:#fff;--ink:#281e22;--muted:#6e6166;--line:#e7dfe2;--danger:#82112b;--danger-soft:#fbecef;--ok:#1d6b45;--ok-soft:#e8f4ed;--radius:.75rem;--shadow:0 1px 2px rgb(40 30 34/.06),0 4px 16px rgb(40 30 34/.06)}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:var(--bg);color:var(--ink);font:1rem/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
a{color:var(--brand)}
.bar{background:var(--surface);border-bottom:1px solid var(--line)}
.bar-in,main{max-width:60rem;margin:auto;padding:0 1.25rem}
.bar-in{display:flex;align-items:center;justify-content:space-between;gap:1rem;min-height:4rem}
.brand{display:flex;align-items:center;gap:.65rem;color:var(--ink);text-decoration:none;font-weight:700;letter-spacing:-.01em}
.brand svg{width:2rem;height:2rem;flex:none}.brand small{display:block;color:var(--muted);font-weight:500;font-size:.78rem;line-height:1.2}
.user{display:flex;align-items:center;gap:.75rem;color:var(--muted);font-size:.9rem}
main{padding-top:2rem;padding-bottom:3rem}
main.narrow{max-width:26rem;padding-top:min(12vh,6rem)}
h1{font-size:clamp(1.6rem,4vw,2.1rem);line-height:1.15;letter-spacing:-.02em;margin:0 0 .35rem}
h2{font-size:1.05rem;margin:2rem 0 .85rem}
.lead{color:var(--muted);margin:0 0 1.5rem}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.5rem}
.notice{display:flex;gap:.6rem;align-items:flex-start;border-radius:.6rem;padding:.8rem 1rem;margin:0 0 1.25rem;font-size:.95rem;list-style:none}
.notice li{padding:0}.notice::before{font-weight:700}
[role=alert]{background:var(--danger-soft);color:var(--danger);border:1px solid #f1cdd5}[role=alert]::before{content:"!"}
[role=status]{background:var(--ok-soft);color:var(--ok);border:1px solid #c7e3d3}[role=status]::before{content:"✓"}
form p,.field{margin:0 0 1.1rem}
label{display:block;font-weight:600;font-size:.9rem;margin-bottom:.35rem}
input,select,button{font:inherit}
input,select{width:100%;padding:.65rem .8rem;border:1px solid #cfc3c8;border-radius:.5rem;background:var(--surface);color:var(--ink);transition:border-color .15s,box-shadow .15s}
input:focus,select:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px rgb(101 28 57/.18)}
button{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;border:0;border-radius:.5rem;padding:.7rem 1.2rem;background:var(--brand);color:#fff;font-weight:600;cursor:pointer;transition:background .15s}
button:hover{background:var(--brand-dark)}button:focus-visible,a:focus-visible{outline:3px solid rgb(101 28 57/.35);outline-offset:2px}
button.ghost{background:transparent;color:var(--brand);border:1px solid var(--line);padding:.45rem .9rem;font-size:.9rem}button.ghost:hover{background:var(--brand-soft)}
.block{width:100%}
.chips{display:flex;flex-wrap:wrap;gap:.4rem;margin:0 0 1.5rem;padding:0;list-style:none}
.chip{display:inline-block;padding:.15rem .6rem;border-radius:99px;background:var(--brand-soft);color:var(--brand);font-size:.8rem;font-weight:600}
.chip.plain{background:#efebe8;color:var(--muted)}
.grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));padding:0;margin:0;list-style:none}
.tile{display:flex;flex-direction:column;gap:.25rem;height:100%;text-decoration:none;color:inherit;transition:border-color .15s,transform .15s}
a.tile:hover{border-color:var(--brand);transform:translateY(-1px)}
.tile small{color:var(--muted)}.tile strong{font-size:1.1rem}.tile .chip{align-self:flex-start;margin-top:.5rem}
.empty{color:var(--muted);text-align:center;padding:2rem}
.back{display:inline-block;margin-bottom:1rem;font-size:.9rem;text-decoration:none}.back:hover{text-decoration:underline}
.pair{display:grid;gap:0 1rem;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr))}
.amount{position:relative}.amount input{padding-right:4rem}.amount span{position:absolute;right:.8rem;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.85rem;font-weight:600}
@media (max-width:30rem){.user span{display:none}.card{padding:1.15rem}}
</style></head><body>
<header class="bar"><div class="bar-in">
<a class="brand" href="{{ url('/') }}">{!! $mark !!}<span>Midnight Travel<small>Accounting</small></span></a>
@auth<div class="user"><span>{{ auth()->user()->name }}</span><form action="{{ url('/logout') }}" method="post">@csrf<button class="ghost">Sign out</button></form></div>@endauth
</div></header>
<main @hasSection('narrow') class="narrow" @endif>
@hasSection('narrow')<div class="card">@endif
@yield('before')
<h1>@yield('title')</h1>
@yield('lead')
@if($errors->any())<ul class="notice" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
@if(session('message'))<p class="notice" role="status">{{ session('message') }}</p>@endif
@yield('content')
@hasSection('narrow')</div>@endif
</main></body></html>
