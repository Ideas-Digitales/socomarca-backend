<?php

use App\Http\Controllers\ApiDocLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/docs/login', [ApiDocLoginController::class, 'showLoginForm'])->name('api-doc.login');
    Route::post('/docs/login', [ApiDocLoginController::class, 'login'])->name('api-doc.login.submit');
});

Route::post('/docs/logout', [ApiDocLoginController::class, 'logout'])->name('api-doc.logout');
