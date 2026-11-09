<?php

use App\Models\Family;
use App\Models\User;
use App\Services\AllowanceLedger;
use App\Services\EvidencePhoto;
use App\Services\MissionNotices;
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

Schedule::call(fn () => app(MissionNotices::class)->deliver())
    ->name('dag:deliver-mission-notices')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => app(EvidencePhoto::class)->prune())
    ->name('dag:prune-reviewed-mission-photos')->daily()->withoutOverlapping();

Schedule::call(function (): void {
    $ledger = app(AllowanceLedger::class);
    User::where('role', User::CHILD)->where('active', true)->eachById(fn (User $child) => $ledger->settle($child));
})->name('dag:close-monthly-allowances')->everyMinute()->withoutOverlapping();
