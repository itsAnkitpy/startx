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

/*
| Module 07 step 2 in a real browser: Deepak withdraws his own resignation from the raise
| screen, and Rakesh moves Anjali's last working day on her exit's page, sees the new
| deadline before saving, and then withdraws it.
*/

it('lets Deepak withdraw his own resignation from the raise screen before anybody acts on it', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, function () {
        $deepak = User::query()->where('work_email', 'deepak@meridian.test')->sole();
        $engine = new CaseEngine;

        $engine->cancel(ProcessCase::query()->where('subject_user_id', $deepak->getKey())->sole(), User::query()->where('work_email', 'chandni@meridian.test')->sole(), 'Raised again');
        $engine->open(ProcessCase::query()->where('subject_user_id', $deepak->getKey())->sole()->template, $deepak, $deepak, now()->addDays(30)->toDateString(), 'resignation');
    });

    browseAs(personAt(MeridianSeeder::Slug, 'deepak'), '/admin/raise-a-request')
        ->select('#raise-process', 'Exit')->wait(0.5)
        ->assertSee('Your exit is open.')
        ->fill('#withdraw-reason', 'Changed my mind')->wait(0.5)
        ->press('Withdraw my exit')
        ->assertSee('Your exit is withdrawn.')
        ->assertNoSmoke();
});

it('lets Rakesh move Anjali\'s last working day, seeing the new deadline first, and then withdraw her exit', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
    $later = CarbonImmutable::now()->addWeeks(6)->next(CarbonImmutable::FRIDAY);

    [$anjalis, $due] = TenantContext::run($meridian, function () use ($later): array {
        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        return [$anjalis, (new CaseEngine)->deadlinesIfItCountedFrom($anjalis, $later->toDateString(), 'resignation')[0]];
    });

    browseAs(personAt(MeridianSeeder::Slug, 'rakesh'), '/admin/cases/'.$anjalis->getKey())
        ->click('Change last working day')
        ->fill('[id$=".last_working_day"]', $later->toDateString())->wait(0.5)
        ->assertSee('Settlement would be due by '.$due->format('j F Y').'.')
        ->fill('[id$=".reason"]', 'Notice extended')
        ->click('Save the change')
        ->assertSee('Last working day changed')
        ->assertSee('Last working day moved from')
        ->assertSee('The last working day on an exit has changed')
        ->click('Withdraw')
        ->fill('[id$=".reason"]', 'Staying after a counter-offer')
        ->click('Withdraw it')
        ->assertSee('Exit withdrawn')
        ->assertSee('Withdrawn by Rakesh Menon')
        ->assertNoSmoke();
});
