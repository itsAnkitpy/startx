<?php

use App\Models\NotificationTemplate;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;

/*
| Module 06 in a real browser: Chandni rewords a message, Anjali chases the request
| waiting on Rakesh's approval, and Anjali chooses one email a day on her own account page.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

it('lets Chandni reword the message telling somebody it is their turn', function () {
    $template = TenantContext::run($this->meridian, fn (): NotificationTemplate => NotificationTemplate::query()
        ->where('key', TemplateCatalogue::StepAvailableHolder)->sole());

    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), "/admin/notification-templates/{$template->getKey()}/edit")
        ->fill('[id$=".subject"]', 'Your turn on {{ case.number }}: {{ step.name }}')
        ->press('Save changes')
        ->assertSee('Saved')
        ->navigate('/admin/notification-templates')
        ->assertVisible('.fi-ta-row:has-text("Your turn on {{ case.number }}"):has-text("Yours")')
        ->assertNoSmoke();
});

it('lets Anjali chase her request, which reaches Priya because she is covering for Rakesh', function () {
    $request = TenantContext::run($this->meridian, fn (): ProcessCase => ProcessCase::query()
        ->whereNull('subject_user_id')->orderBy('id')->firstOrFail());

    browseAs(personAt(MeridianSeeder::Slug, 'anjali'), "/admin/cases/{$request->getKey()}")
        ->click('Chase')
        ->click('Chase them')
        // A chase skips whoever is away while somebody covers for them, and the demo has
        // Priya holding Rakesh's hiring approvals this fortnight.
        ->assertSee('Chased Priya Nair')
        ->assertSee('Anjali Rao pressed the chase button')
        ->assertNoSmoke();
});

it('lets Anjali choose one email a day, at an hour she picks, on her account page', function () {
    $anjali = personAt(MeridianSeeder::Slug, 'anjali');

    // The hour only appears once the daily email is chosen, which only the page itself
    // can show.
    browseAs($anjali, '/admin/profile')
        ->assertDontSee('Send it at')
        ->click('One email a day with everything in it')
        ->select('[id$=".daily_summary_hour"]', '7 am')
        ->press('Save changes')
        ->assertSee('Saved');

    browseAs($anjali, '/admin/profile')
        ->assertChecked('[value="daily"]')
        ->assertSelected('[id$=".daily_summary_hour"]', '7');
});
