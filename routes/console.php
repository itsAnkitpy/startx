<?php

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Notifications\Chasing;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The one scheduled pass in the product (module 06). Every minute, for every client company:
| remind whoever holds a step that is part-way through its time, tell whoever is above it
| once it has run out, and warn when a case has passed the deadline the law sets.
|
| Each message goes out in its own short write, so a slow mail server holds up one message
| rather than a whole client's, and a failure cannot take the record of the others with it.
| One client company failing is reported and the rest are still chased.
|
| ponytail: sent here rather than queued, because the queue needs a worker running and this
| volume does not. Move the sends onto the queue when one pass stops fitting in its minute.
|
| Never two at once, and a pass killed part-way leaves its lock behind: ten minutes rather
| than Laravel's default of a day is how long chasing then stops.
*/
Artisan::command('steps:chase', function (): void {
    foreach (Tenant::query()->where('active', true)->orderBy('id')->get() as $company) {
        try {
            $owed = TenantContext::run($company, fn () => (new Chasing)->owed());

            $sent = collect($owed)
                ->map(fn (Closure $send) => TenantContext::run($company, $send))
                ->filter(fn (?NotificationDelivery $delivery) => $delivery?->status === NotificationDelivery::Sent)
                ->count();

            $this->line($company->name.': '.$sent.' of '.count($owed).' sent');
        } catch (Throwable $failed) {
            report($failed);
            $this->error($company->name.': '.$failed->getMessage());
        }
    }
})->purpose('Chase late steps: remind, escalate at the deadline, and warn when a legal deadline has passed')
    ->everyMinute()
    ->withoutOverlapping(10);
