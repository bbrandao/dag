<?php

use App\Http\Controllers\ChildController;
use App\Http\Controllers\MissionController;
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
    Route::patch('/guardian/tasks/{task}', [TaskController::class, 'update'])->whereUuid('task');
    Route::delete('/guardian/tasks/{task}', [TaskController::class, 'destroy'])->whereUuid('task');
    Route::get('/app/missoes', [TaskController::class, 'missions'])->name('missions');
    Route::get('/app/missoes/{mission}', [MissionController::class, 'show'])->whereUuid('mission')->name('missions.show');
    Route::post('/app/missoes/{mission}/entregas', [MissionController::class, 'submit'])->whereUuid('mission');
    Route::get('/app/missoes/{mission}/fotos/{submission}', [MissionController::class, 'photo'])
        ->whereUuid(['mission', 'submission'])->name('missions.photo');
    Route::get('/guardian/revisoes', [MissionController::class, 'reviews'])->name('reviews');
    Route::get('/guardian/revisoes/{mission}', [MissionController::class, 'show'])->whereUuid('mission')->name('reviews.show');
    Route::post('/guardian/revisoes/{mission}', [MissionController::class, 'review'])->whereUuid('mission');
    Route::post('/guardian/revisoes/{mission}/reabrir', [MissionController::class, 'reopen'])->whereUuid('mission');
    Route::patch('/app/avisos', [DashboardController::class, 'updateNotices']);
});
