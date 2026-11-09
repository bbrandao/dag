<?php

use App\Http\Controllers\ChildController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [OtpController::class, 'show'])->name('login');
    Route::post('/auth/otp', [OtpController::class, 'issue'])->middleware('throttle:otp-issue')->block();
    Route::post('/auth/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify')->block();
    Route::post('/auth/otp/reset', [OtpController::class, 'reset'])->block();
});

Route::middleware(['auth', 'active.account'])->group(function (): void {
    Route::get('/app', DashboardController::class)->name('dashboard');
    Route::post('/logout', [OtpController::class, 'logout'])->block();
    Route::post('/guardian/children', [ChildController::class, 'store']);
    Route::get('/guardian/tasks', [TaskController::class, 'index'])->name('guardian.tasks');
    Route::post('/guardian/tasks', [TaskController::class, 'store']);
    Route::get('/app/missoes', [TaskController::class, 'missions'])->name('missions');
});
