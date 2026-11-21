<?php

use App\Http\Controllers\StaffTreasuryController as Staff;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'accounting.login')->name('login');
    Route::post('/login', [Staff::class, 'login'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/', [Staff::class, 'home'])->name('home');
    Route::post('/logout', [Staff::class, 'logout']);
    Route::get('/branches/{branch}', [Staff::class, 'branch'])->whereNumber('branch');
    Route::post('/branches/{branch}/transfers', [Staff::class, 'transfer'])->whereNumber('branch');
});
