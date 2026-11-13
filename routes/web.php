<?php

use App\Http\Controllers\ChildController;
use App\Http\Controllers\AllowanceController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SettingsController;
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
    Route::get('/guardian/configuracoes', [SettingsController::class, 'show'])->name('settings');
    Route::post('/guardian/configuracoes/valor-do-ponto', [SettingsController::class, 'saveRate']);
    Route::get('/guardian/children', [ChildController::class, 'index'])->name('children.index');
    Route::post('/guardian/children', [ChildController::class, 'store']);
    Route::get('/guardian/children/{child}/editar', [ChildController::class, 'edit'])->whereUuid('child')->name('children.edit');
    Route::patch('/guardian/children/{child}', [ChildController::class, 'update'])->whereUuid('child');
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
    Route::get('/app/avisos', [InboxController::class, 'show'])->name('inbox');
    Route::get('/app/avisos/contagem', [InboxController::class, 'count']);
    Route::get('/app/avisos/resumo', [InboxController::class, 'summary']);
    Route::patch('/app/avisos/lidos', [InboxController::class, 'read']);
    Route::middleware('throttle:20,1')->prefix('/app/push')->group(function (): void {
        Route::post('/status', [PushController::class, 'status'])->block();
        Route::post('/assinatura', [PushController::class, 'store'])->block();
        Route::delete('/assinatura', [PushController::class, 'destroy'])->block();
    });
    Route::get('/app/ganhos', [AllowanceController::class, 'show'])->name('allowance.child');
    Route::get('/guardian/mesadas/{child}', [AllowanceController::class, 'show'])->whereUuid('child')->name('allowance.guardian');
    Route::post('/guardian/mesadas/{child}/conversao', [AllowanceController::class, 'rate'])->whereUuid('child');
    Route::post('/guardian/mesadas/{child}/fechar', [AllowanceController::class, 'close'])->whereUuid('child');
    Route::patch('/guardian/mesadas/{child}/contas/{payout}/paga', [AllowanceController::class, 'pay'])->whereUuid(['child', 'payout']);
});
