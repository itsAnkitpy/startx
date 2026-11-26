<?php

use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
        ->assertSee($lastDay->format('j F Y'))
        ->assertSee($lastDay->addDays(4)->format('j F Y'))
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

/*
| Module 07 step 3 in a real browser: Chandni closes her own hold on Deepak's finance
| clearance as disputed from her queue, and Rakesh overrides her hold on Anjali's from
| Anjali's exit page.
*/

it('lets Chandni close her hold as disputed from her queue, and Rakesh override one from the exit page', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    $anjalis = TenantContext::run($meridian, function (): ProcessCase {
        $chandni = User::query()->where('work_email', 'chandni@meridian.test')->sole();
        $engine = new CaseEngine;

        foreach (['Deepak', 'Anjali'] as $leaver) {
            $exit = ProcessCase::query()->whereRelation('subject', 'first_name', $leaver)->sole();

            $engine->claim($exit, 3, $chandni);
            $engine->decide($exit->fresh(), 3, 'held', $chandni, ['imprest_card_returned' => false, 'recover_from_them' => 40000], 'Imprest card not returned');
        }

        return ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();
    });

    $card = '.fi-ta-record:has-text("about Deepak Iyer")';

    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), '/admin/my-queue')
        ->click($card.' button:has-text("Close as disputed")')
        ->assertSee('stays on it as disputed')
        ->fill($card.' textarea[id^="reason-"]', 'Deepak disputes the figure')
        // The second "Close as disputed" on the card: the first only opened the reason box.
        ->click($card.' button:has-text("Close as disputed") >> nth=1')
        ->assertSee('Recorded.')
        ->assertNoSmoke();

    browseAs(personAt(MeridianSeeder::Slug, 'rakesh'), '/admin/cases/'.$anjalis->getKey())
        ->click('Override the hold')
        ->assertSee('Finance clearance — held by Chandni Verma')
        ->fill('[id$=".reason"]', 'Chandni is on leave')
        ->click('Override it')
        ->assertSee('Hold overridden')
        ->assertSee("Closed over Chandni Verma's hold by Rakesh Menon")
        ->assertNoSmoke();
});

/*
| Module 07 step 4 in a real browser: Rakesh has answered his own link and Priya's final
| check is done, so on his last working day his letters are the answer that would finish his
| exit (module 09 step 3), and they are refused while his roles and team are still on him.
| Chandni sees why on the exit's page, hands his work to Priya, and Priya's press then issues
| his letters and finishes it.
*/

it('refuses Rakesh\'s letters while his work is on him, and finishes his exit once Chandni hands it over', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
    Storage::fake(config('startx.documents_disk'));

    $rakeshs = TenantContext::run($meridian, function (): ProcessCase {
        $exit = ProcessCase::query()->whereRelation('subject', 'first_name', 'Rakesh')->sole();

        (new CaseEngine)->decideThroughALink(basename((new StepLink)->issue($exit, 4)), 'approved');
        (new CaseEngine)->decide($exit->fresh(), 5, 'approved', User::query()->where('work_email', 'priya@meridian.test')->sole());

        return $exit;
    });

    $this->travelTo(CarbonImmutable::parse($rakeshs->statutory_from->toDateString().' 10:00', 'Asia/Kolkata'));

    $card = '.fi-ta-record:has-text("about Rakesh Menon")';

    browseAs(personAt(MeridianSeeder::Slug, 'priya'), '/admin/my-queue')
        ->click($card.' button:has-text("Issue the letters")')
        ->assertSee('Rakesh Menon still has 2 roles and 2 people reporting to them')
        ->assertNoSmoke();

    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), '/admin/cases/'.$rakeshs->getKey())
        ->assertSee('Waiting on a handover')
        ->click('Hand over their work')
        ->click('.fi-fo-field:has-text("Who takes it on") .fi-select-input-btn')
        ->click('.fi-fo-field:has-text("Who takes it on") [role="option"]:has-text("Priya Nair")')
        ->click('Hand it over')
        ->assertSee('Priya Nair has taken the work on')
        ->assertDontSee('Waiting on a handover')
        ->assertNoSmoke();

    browseAs(personAt(MeridianSeeder::Slug, 'priya'), '/admin/my-queue')
        ->click($card.' button:has-text("Issue the letters")')
        ->assertSee('Recorded.')
        ->assertNoSmoke();

    browseAs(personAt(MeridianSeeder::Slug, 'priya'), '/admin/cases/'.$rakeshs->getKey())
        ->assertSee('Finished')
        ->assertSee('Documents issued')
        ->assertSee('Settlement statement')
        ->assertNoSmoke();
});

/*
| Module 07 step 5 in a real browser: Chandni reads on Rakesh's exit when his sign-in
| switches off. Once his last working day has ended on Meridian's clock the every-minute
| pass switches it off, Rakesh is refused at the sign-in screen, and Chandni reads on his
| exit that it went off and when.
*/

it('switches Rakesh\'s sign-in off once his last working day has ended, and says so on his exit\'s page', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
    $rakeshs = TenantContext::run($meridian, fn (): ProcessCase => ProcessCase::query()->whereRelation('subject', 'first_name', 'Rakesh')->sole());
    $lastDay = $rakeshs->statutory_from->format('j F Y');

    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), '/admin/cases/'.$rakeshs->getKey())
        ->assertSee('Switches off once '.$lastDay.' has ended')
        ->assertNoSmoke();

    // The morning after, in India.
    $this->travelTo(CarbonImmutable::parse($rakeshs->statutory_from->toDateString().' 09:00', 'Asia/Kolkata')->addDay());
    Artisan::call('steps:chase');

    test()->flushSession();
    Auth::guard()->forgetUser();
    pest()->browser()->withHost(MeridianSeeder::Slug.'.'.config('tenancy.central_domain'));

    visit('/admin/login')
        ->fill('[id$="email"]', 'rakesh@meridian.test')
        ->fill('[id$="password"]', MeridianSeeder::Password)
        ->press('[type="submit"]')
        ->assertSee('Your account is switched off, so you cannot sign in.')
        ->assertPathIs('/admin/login')
        ->assertNoSmoke();

    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), '/admin/cases/'.$rakeshs->getKey())
        ->assertSee('Switched off at the end of '.$lastDay)
        ->assertSee('Sign-in switched off at the end of the last working day, '.$lastDay.'.')
        ->assertNoSmoke();
});
