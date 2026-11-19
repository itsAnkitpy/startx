<?php

use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;

/*
| Modules 02 to 05 in a real browser: Anjali raises a hiring request, Rakesh answers it
| from his queue, and the case's page says what happened.
|
| Rakesh's queue already holds the two seeded hiring requests at the same step, so a card
| is always found by words only its own request carries — never by the step's name, which
| all three share.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);
});

/** The newest case at Meridian, which is the one a test has just raised. */
function meridiansNewestCase(): ProcessCase
{
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    return TenantContext::run($meridian, fn (): ProcessCase => ProcessCase::query()->latest('id')->firstOrFail());
}

it('lets Anjali raise a hiring request that Rakesh approves from his queue, and shows it on the case page', function () {
    $justification = 'A third officer for the Pune depot night shift.';

    browseAs(personAt(MeridianSeeder::Slug, 'anjali'), '/admin/raise-a-request')
        // Every answer goes to the server as it is given, and the page redraws when the
        // reply comes back. A box left while a reply is still on its way is redrawn empty,
        // so each answer waits for its own reply. No person is quick enough to hit this.
        ->select('#raise-process', 'Hiring request')->wait(0.5)
        ->select('#q-answers-department', 'Shimla branch')->wait(0.5)
        ->select('#q-answers-designation', 'Operations Officer')->wait(0.5)
        ->select('#q-answers-replaces_a_leaver', 'New headcount')->wait(0.5)
        ->fill('#q-answers-positions', '1')->wait(0.5)
        ->fill('#q-answers-annual_ctc', '800000')->wait(0.5)
        ->select('#q-answers-employment_type', 'Permanent')->wait(0.5)
        ->fill('#q-answers-justification', $justification)
        ->press('Raise it')
        ->assertSee('Hiring request raised.')
        // Checked last, not on arrival: raising stays on this page, so an error thrown
        // while Anjali was answering is still here to be found.
        ->assertNoSmoke();

    $card = '.fi-ta-record:has-text("'.$justification.'")';

    browseAs(personAt(MeridianSeeder::Slug, 'rakesh'), '/admin/my-queue')
        ->click($card.' button:has-text("Approve")')
        ->assertSee('Recorded.')
        ->assertDontSee($justification);

    browseAs(personAt(MeridianSeeder::Slug, 'anjali'), '/admin/cases/'.meridiansNewestCase()->getKey())
        ->assertSee('Approved by Rakesh Menon')
        // Under the salary at which the director is asked, so it finished with him.
        ->assertSee('Not needed this time')
        ->assertNoSmoke();
});

it('puts the answers back in Anjali\'s boxes when Rakesh sends her request back', function () {
    $card = '.fi-ta-record:has-text("Replacing Deepak Iyer, whose exit is already running.")';

    browseAs(personAt(MeridianSeeder::Slug, 'rakesh'), '/admin/my-queue')
        ->click($card.' button:has-text("Send it back")')
        ->fill($card.' textarea[id^="reason-"]', 'Say which shift the officer covers.')
        // The second "Send it back" on the card: the first only opened the reason box.
        ->click($card.' button:has-text("Send it back") >> nth=1')
        ->assertSee('Recorded.');

    // The trap this exists for: Livewire fills these boxes after the page loads, so the
    // HTML the server sends is empty whatever happened. Only a browser can tell.
    browseAs(personAt(MeridianSeeder::Slug, 'anjali'), '/admin/my-queue')
        ->assertSee('Say which shift the officer covers.')
        ->assertValue('textarea[id$="-justification"]', 'Replacing Deepak Iyer, whose exit is already running.')
        ->assertValue('input[id$="-annual_ctc"]', '900000')
        ->assertSelected('select[id$="-employment_type"]', 'permanent');
});
