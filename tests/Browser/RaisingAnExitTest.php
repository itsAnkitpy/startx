<?php

use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;

/*
| Module 07 step 1 in a real browser: Deepak resigns from the raise screen and is told
| where everything about it will be sent, without being shown the exit's own page, which
| is not his to read. Rakesh, his HR head, opens it and sees how he is leaving beside both
| legal deadlines.
|
| His seeded exit is withdrawn first, because everybody at Meridian bar Chandni has one
| running in the demo and a person has one open exit at a time.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);
});

it('lets Deepak raise his own resignation, and shows its deadlines to his HR head on the exit page', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $deepak = User::query()->where('work_email', 'deepak@meridian.test')->sole();
        $chandni = User::query()->where('work_email', 'chandni@meridian.test')->sole();

        (new CaseEngine)->cancel(
            ProcessCase::query()->where('subject_user_id', $deepak->getKey())->sole(), $chandni, 'Withdrew the resignation'
        );
    });

    // A Friday, so the settlement date shows two working days counted past a weekend.
    $lastDay = CarbonImmutable::now()->addWeeks(3)->next(CarbonImmutable::FRIDAY);

    browseAs(personAt(MeridianSeeder::Slug, 'deepak'), '/admin/raise-a-request')
        // Each answer waits for its own reply, for the reason the hiring walk-through gives.
        ->select('#raise-process', 'Exit')->wait(0.5)
        ->assertSee('Your own exit is recorded as a resignation.')
        ->assertValue('#exit-personal-email', 'deepak@personal.example')
        ->fill('#exit-last-day', $lastDay->toDateString())->wait(0.5)
        ->press('Raise the exit')
        ->assertSee('Your exit is raised.')
        ->assertSee('Everything about it from now on is sent to deepak@personal.example.')
        ->assertPathIs('/admin/raise-a-request')
        ->assertNoSmoke();

    $raised = TenantContext::run($meridian, fn (): ProcessCase => ProcessCase::query()
        ->whereRelation('subject', 'first_name', 'Deepak')->whereNull('cancelled_at')->sole());

    browseAs(personAt(MeridianSeeder::Slug, 'rakesh'), '/admin/cases/'.$raised->getKey())
        ->assertSee('Resignation')
        ->assertSee($lastDay->format('M j, Y'))
        ->assertSee($lastDay->addDays(4)->format('M j, Y'))
        ->assertSee('Not owed')
        ->assertNoSmoke();
});
