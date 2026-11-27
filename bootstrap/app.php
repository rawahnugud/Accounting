<?php

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Too many sign-in attempts: stay on the form instead of a bare 429 page.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('login')) {
                $wait = (int)($e->getHeaders()['Retry-After'] ?? 60);
                return redirect()->route('login')->withInput($request->only('email'))
                    ->withErrors(['email' => "Too many sign-in attempts. Try again in {$wait} seconds."]);
            }
        });
        // Expired session or form (419): return to the form with a clear message instead of "Page Expired".
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }
            if (!$request->user()) {
                return redirect()->route('login')->withErrors(['email' => 'Your session expired. Sign in and submit again.']);
            }
            // The same request_key is kept, so resubmitting can never post twice.
            return back()->withInput($request->except('_token'))
                ->withErrors(['session' => 'Your session expired. Please check the details and submit again.']);
        });
    })->create();
