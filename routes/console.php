<?php

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Notifications\Chasing;
use App\Notifications\DailySummary;
use App\Notifications\TryingAgain;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The one scheduled pass in the product (module 06). Every minute, for every client company:
| switch off sign-in for everybody whose last working day has ended (module 07 step 5),
| remind whoever holds a step that is part-way through its time, tell whoever is above it
| once it has run out, warn when a case has passed the deadline the law sets, send each
| daily summary whose hour has come, and try again each message the mail server refused.
| Switching off goes first, so nothing is chased onto somebody who has just gone.
|
| Each message and each switch-off goes out in its own short write, so a slow mail server
| holds up one message rather than a whole client's, and a failure cannot take the record of
| the others with it. Each catches its own error, so one that keeps failing cannot hold up
| the rest of its company every minute. One client company failing is reported and the rest
| are still chased.
|
| ponytail: sent here rather than queued, because the queue needs a worker running and this
| volume does not. Move the sends onto the queue when one pass stops fitting in its minute.
|
| Never two at once, and a pass killed part-way leaves its lock behind: ten minutes rather
| than Laravel's default of a day is how long chasing then stops.
*/
Artisan::command('steps:chase', function (): void {
    foreach (Tenant::query()->where('active', true)->orderBy('id')->get() as $company) {
        $inItsOwnWrite = function (Closure $work) use ($company): mixed {
            try {
                return TenantContext::run($company, $work);
            } catch (Throwable $failed) {
                report($failed);

                return null;
            }
        };

        try {
            $switchedOff = collect(TenantContext::run($company, fn () => (new CaseEngine)->signInsOwedSwitchingOff()))
                ->filter(fn (Closure $switchOff) => $inItsOwnWrite($switchOff) === true)
                ->count();

            $owed = TenantContext::run($company, fn () => [
                ...(new Chasing)->owed(),
                ...(new DailySummary)->owed(),
                ...(new TryingAgain)->owed(),
            ]);

            $sent = collect($owed)
                ->map($inItsOwnWrite)
                ->filter(fn (?NotificationDelivery $delivery) => $delivery?->status === NotificationDelivery::Sent)
                ->count();

            $this->line($company->name.': '.$sent.' of '.count($owed).' sent'
                .($switchedOff > 0 ? ', sign-in switched off for '.$switchedOff : ''));
        } catch (Throwable $failed) {
            report($failed);
            $this->error($company->name.': '.$failed->getMessage());
        }
    }
})->purpose('Switch off sign-in once a last working day has ended, chase late steps, warn when a legal deadline has passed, send each daily summary at its hour, and try again each message the mail server refused')
    ->everyMinute()
    ->withoutOverlapping(10);
