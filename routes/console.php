<?php

use App\Models\Family;
use App\Services\TaskCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => DB::table('otp_challenges')->where('expires_at', '<=', now())->delete())
    ->name('dag:purge-expired-otp')
    ->daily()
    ->withoutOverlapping();

Schedule::call(function (): void {
    $calendar = app(TaskCalendar::class);
    Family::eachById(fn (Family $family) => $calendar->materialize($family));
})->name('dag:materialize-tasks')->everyMinute()->withoutOverlapping();
