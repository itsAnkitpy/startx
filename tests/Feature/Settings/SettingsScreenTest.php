<?php

use App\Exceptions\ProcessRefused;
use App\Exceptions\SettingRefused;
use App\Filament\Pages\CompanySettings;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Livewire\Livewire;

/*
| The screen a client changes their own switches on, and the money kind it draws.
|
| Two claims are being checked. One, a switch that holds money is a kind of its own: the
| store refuses a figure that is not whole rupees, and a step may be opened by "more than"
| that switch — which a switch holding words may not. Two, the screen is built from the
| declared list and not from a page that names any switch, so the next module to declare
| one gets a control without touching this page.
|
| It runs against the demo company as it is actually seeded, so what is checked is the
| screen Ankit opens rather than a fixture arranged to suit the test.
*/

/** Meridian, seeded, with somebody signed in and the company in scope. */
function meridianSeeded(): Tenant
{
    test()->seed(MeridianSeeder::class);

    return Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
}

function meridiansPersonCalled(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

it('draws a control for every switch the code declares, and names none of them itself', function () {
    $meridian = meridianSeeded();

    TenantContext::run($meridian, function () {
        // Chandni is one of Meridian's two administrators, which is what carries the
        // action of changing the company's settings.
        $page = Livewire::actingAs(meridiansPersonCalled('chandni'))->test(CompanySettings::class)
            ->assertOk();

        // Every declared switch, by the words it is called by on screen and the line of
        // help underneath it — both read off the declaration rather than typed into the
        // page. The salary threshold is module 05's; the stand-in came with module 03.
        foreach (Settings::declared() as $declaration) {
            $page->assertSee($declaration->label)->assertSee($declaration->help);
        }

        // And nothing on the page names a switch, which is what makes the next module's
        // switch appear here on its own.
        expect(file_get_contents(app_path('Filament/Pages/CompanySettings.php')))
            ->not->toContain('hiring_director_threshold');
    });
});

it('saves a new salary threshold and reads it back', function () {
    $meridian = meridianSeeded();

    TenantContext::run($meridian, function () {
        $settings = app(Settings::class);

        // Fifteen lakh is what a client starts with, having never touched it — and no row
        // is written for a figure nobody has changed.
        expect($settings->get('hiring_director_threshold'))->toBe(1500000)
            ->and(TenantSetting::query()->where('key', 'hiring_director_threshold')->count())->toBe(0);

        Livewire::actingAs(meridiansPersonCalled('chandni'))->test(CompanySettings::class)
            ->fillForm(['hiring_director_threshold' => '2500000'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Stored as a whole number rather than as the text the box handed back, which is
        // the shape the switch declares and the shape a "more than" can be read against.
        $settings->forget();

        expect($settings->get('hiring_director_threshold'))->toBe(2500000)
            ->and(TenantSetting::query()->where('key', 'hiring_director_threshold')->sole()->value)
            ->toBe(2500000);

        // And only the switch that moved. The page carries every switch there is, so
        // saving one must not stamp Chandni onto the others as though she had changed
        // them. Meridian is seeded with a stand-in already chosen, by nobody, and it stays
        // that way.
        expect(TenantSetting::query()->where('key', 'hiring_director_threshold')->sole()->updated_by)
            ->toBe(meridiansPersonCalled('chandni')->getKey())
            ->and(TenantSetting::query()->where('key', '!=', 'hiring_director_threshold')->sole()->updated_by)
            ->toBeNull();
    });
});

it('refuses a figure the switch own rule rejects, under the box it belongs to', function () {
    $meridian = meridianSeeded();

    TenantContext::run($meridian, function () {
        // A salary threshold below zero. Refused under the box rather than as an error
        // page, because the person is being asked to correct it.
        Livewire::actingAs(meridiansPersonCalled('chandni'))->test(CompanySettings::class)
            ->fillForm(['hiring_director_threshold' => '-1'])
            ->call('save')
            ->assertHasFormErrors(['hiring_director_threshold']);

        expect(TenantSetting::query()->where('key', 'hiring_director_threshold')->count())->toBe(0);
    });
});

it('keeps the settings screen shut to somebody without that action', function () {
    $meridian = meridianSeeded();

    TenantContext::run($meridian, function () {
        // Rakesh runs HR for Shimla and is not one of the company's administrators. He can
        // clear an exit all day and cannot change the figure the whole company escalates
        // on, which is the reason changing settings is an action of its own.
        expect(CompanySettings::canAccess())->toBeFalse()
            ->and(auth()->check())->toBeFalse();

        $this->actingAs(meridiansPersonCalled('rakesh'));

        expect(CompanySettings::canAccess())->toBeFalse();

        $this->actingAs(meridiansPersonCalled('chandni'));

        expect(CompanySettings::canAccess())->toBeTrue();
    });
});

it('refuses a money switch a value that is not whole rupees', function () {
    $meridian = meridianSeeded();

    TenantContext::run($meridian, function () {
        // The rule alone does not settle this: Laravel counts the text "2500000" as a
        // whole number, so a switch declared as money could store text and read text back
        // — and a "more than" against text is not the comparison anybody meant.
        expect(fn () => app(Settings::class)->set('hiring_director_threshold', '2500000'))
            ->toThrow(SettingRefused::class, 'must be a whole number of rupees, and this is string');

        // Fifty paise on a salary threshold is refused a step earlier, by the switch's own
        // rule, which is the other half of the pair — the rule catches what is plainly
        // wrong and the kind catches what only looks right.
        expect(fn () => app(Settings::class)->set('hiring_director_threshold', 2500000.50))
            ->toThrow(SettingRefused::class, 'The value field must be an integer');

        expect(TenantSetting::query()->where('key', 'hiring_director_threshold')->count())->toBe(0);
    });
});

it('lets a step open on more than a money switch, and refuses one on more than words', function () {
    $meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian-settings']);

    TenantContext::run($meridian, function () {
        // The real declaration, holding money. A director's approval opening above the
        // figure the client set is the whole reason the money kind exists.
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Package')->collecting('annual_ctc')->create();

        ProcessStep::factory()->of($hiring)->at(2, 2)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>',
                'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        $hiring->publish();

        expect($hiring->fresh()->status)->toBe(ProcessTemplate::Published);
    });

    TenantContext::run($meridian, function () {
        // The same condition against a switch holding words. Nothing errors at run time —
        // the comparison is simply never true — so the director's step would never open
        // and the case would close approved with nobody having approved it. Which is why
        // it is refused at the moment the process goes live, in words a client can read.
        Settings::declare(new SettingDeclaration(
            key: 'hiring_director_threshold',
            label: 'Salary above which a hire needs the director',
            type: 'text',
            default: 'fifteen lakh',
            rule: 'string',
            help: 'Hires above this annual figure need the director.',
        ));

        $written = ProcessTemplate::factory()->named('hiring_words', 'Hiring request in words')
            ->about('none')->create();

        ProcessStep::factory()->of($written)->at(1, 1)->named('Package')->collecting('annual_ctc')->create();

        ProcessStep::factory()->of($written)->at(2, 2)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>',
                'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        expect(fn () => $written->publish())
            ->toThrow(ProcessRefused::class, 'which holds text rather than a number');
    });
});

/** The settings address on Meridian's own subdomain, which is how anybody reaches it. */
function meridiansSettingsAddress(): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/company-settings';
}

it('serves the settings address to an administrator', function () {
    $meridian = meridianSeeded();

    // The whole way in, exactly as Ankit will: the company's own subdomain puts Meridian
    // in scope and the panel serves the screen.
    $chandni = TenantContext::run($meridian, fn () => meridiansPersonCalled('chandni'));

    $this->actingAs($chandni)->get(meridiansSettingsAddress())
        ->assertOk()
        ->assertSee('Salary above which a hire needs the director');
});

it('refuses the settings address itself to somebody without that action', function () {
    $meridian = meridianSeeded();

    // Asking the screen's own check is not the same as knocking on the door, and the door
    // is what somebody would actually try. Rakesh is signed in and typing the address.
    $rakesh = TenantContext::run($meridian, fn () => meridiansPersonCalled('rakesh'));

    $this->actingAs($rakesh)->get(meridiansSettingsAddress())->assertForbidden();
});
