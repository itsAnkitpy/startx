<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\Delegations\Pages\ManageCover;
use App\Models\Delegation;
use App\Models\OrgUnit;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Cover while somebody is away — the screen for the rows module 03 built and nobody could
| create.
|
| What is being checked beyond the row appearing:
|
| Rakesh is away for a fortnight and Priya holds his hiring approvals, which the demo
| company seeds — so the screen has something on it the moment it is opened, and the two
| requests waiting on Rakesh appear in Priya's queue while the dates run.
|
| Setting cover hands one person's approvals to another, so it needs the action over the
| whole client company. Somebody responsible for the Shimla branch alone is refused the
| screen outright, because a cover names no part of the company for their grant to be
| checked against — they would otherwise name themselves as cover for the finance head.
|
| Three of the record's own rules are met as sentences rather than as error pages: nobody
| covers themselves, a cover cannot end before it starts, and a cover cannot be passed on
| to a third person.
|
| And a cover that has finished stays on the list reading as finished, because a cover that
| quietly disappears is the complaint the comparable products collect.
|
| It runs against the demo company as seeded, so what is checked is the screen Ankit opens.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function meridianColleagueNamed(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/**
 * Give somebody a role carrying the cover action alone, over the whole company or over one
 * named part of it.
 */
function giveMeridianStaffTheCoverAction(User $person, string $key, ?OrgUnit $over = null): void
{
    $role = Role::query()->create(['key' => $key, 'name' => Str::headline($key)]);

    $role->permissions()->create(['permission' => Permission::ManageCover]);

    $role->assignments()->create([
        'user_id' => $person->getKey(),
        'org_unit_id' => $over?->getKey(),
        'includes_descendants' => false,
    ]);

    app(PermissionResolver::class)->forget();
}

/** The cover the demo company seeds: Priya holding Rakesh's hiring approvals. */
function meridiansSeededCover(): Delegation
{
    return Delegation::query()->where('process_key', 'hiring_request')->sole();
}

it('shows an administrator the cover the company has set, in both names', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Delegation::query()->get())
            ->assertSee('Rakesh Menon')
            ->assertSee('Priya Nair')
            // The row stores the process's permanent name, which no client ever sees.
            ->assertSee('Hiring request')
            ->assertDontSee('hiring_request')
            ->assertSee('Running now');
    });
});

it('keeps the cover screen away from somebody with no role at all', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(meridianColleagueNamed('anjali'))->test(ManageCover::class)
            ->assertForbidden();
    });
});

it('keeps the cover screen away from somebody who covers one branch only', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = OrgUnit::query()->where('name', 'Shimla branch')->sole();

        // Deepak now holds the cover action — over the Shimla branch, and nowhere else.
        // A cover names two people and no part of the company, so there is nothing for
        // his grant to be checked against; without this he could name himself as cover
        // for the finance head and collect her approvals.
        giveMeridianStaffTheCoverAction(meridianColleagueNamed('deepak'), 'shimla_cover', $shimla);

        Livewire::actingAs(meridianColleagueNamed('deepak'))->test(ManageCover::class)
            ->assertForbidden();
    });
});

it('lets somebody who covers the whole company open it', function () {
    TenantContext::run($this->meridian, function () {
        // The same role as the test above, granted over the whole company instead. So what
        // the refusal turns on is the reach of the grant and nothing else.
        giveMeridianStaffTheCoverAction(meridianColleagueNamed('deepak'), 'company_cover');

        Livewire::actingAs(meridianColleagueNamed('deepak'))->test(ManageCover::class)
            ->assertOk();
    });
});

it('sets cover for somebody who is going away', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = meridianColleagueNamed('chandni');
        $deepak = meridianColleagueNamed('deepak');
        $anjali = meridianColleagueNamed('anjali');

        Livewire::actingAs($chandni)->test(ManageCover::class)
            ->callAction('create', [
                'user_id' => $deepak->getKey(),
                'delegate_id' => $anjali->getKey(),
                'process_key' => 'exit',
                'effective_from' => '2026-10-01',
                'effective_to' => '2026-10-14',
            ])
            ->assertHasNoActionErrors();

        $set = Delegation::query()->where('user_id', $deepak->getKey())->sole();

        expect((int) $set->delegate_id)->toBe((int) $anjali->getKey())
            // The permanent name of the process, not the words the picker showed.
            ->and($set->process_key)->toBe('exit')
            ->and($set->effective_from->toDateString())->toBe('2026-10-01')
            ->and($set->effective_to->toDateString())->toBe('2026-10-14');
    });
});

it('refuses somebody covering for themselves under the box rather than on an error page', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = meridianColleagueNamed('deepak');

        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->callAction('create', [
                'user_id' => $deepak->getKey(),
                'delegate_id' => $deepak->getKey(),
                'process_key' => 'exit',
                'effective_from' => '2026-10-01',
                'effective_to' => '2026-10-14',
            ])
            ->assertHasActionErrors(['delegate_id']);

        expect(Delegation::query()->where('user_id', $deepak->getKey())->exists())->toBeFalse();
    });
});

it('refuses a cover that ends before it starts', function () {
    TenantContext::run($this->meridian, function () {
        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->callAction('create', [
                'user_id' => meridianColleagueNamed('deepak')->getKey(),
                'delegate_id' => meridianColleagueNamed('anjali')->getKey(),
                'process_key' => 'exit',
                'effective_from' => '2026-10-14',
                'effective_to' => '2026-10-01',
            ])
            ->assertHasActionErrors(['effective_to']);
    });
});

it('offers only the processes the company actually runs', function () {
    TenantContext::run($this->meridian, function () {
        // A process still being written. Choosing it would set a cover for something
        // nobody can start, so the picker never offers it and the answer is refused when
        // it is submitted anyway.
        ProcessTemplate::factory()->named('salary_change', 'Salary change')->about('employee')->create();

        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->callAction('create', [
                'user_id' => meridianColleagueNamed('deepak')->getKey(),
                'delegate_id' => meridianColleagueNamed('anjali')->getKey(),
                'process_key' => 'salary_change',
                'effective_from' => '2026-10-01',
                'effective_to' => '2026-10-14',
            ])
            ->assertHasActionErrors(['process_key']);
    });
});

it('says in a sentence why cover cannot be passed on to a third person', function () {
    TenantContext::run($this->meridian, function () {
        $seeded = meridiansSeededCover();

        // Priya is already holding Rakesh's hiring approvals. Naming her as the one going
        // away, with somebody else covering, would be a chain — and a chain is a queue
        // whose owner nobody can name in one step.
        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->callAction('create', [
                'user_id' => $seeded->delegate_id,
                'delegate_id' => meridianColleagueNamed('deepak')->getKey(),
                'process_key' => 'hiring_request',
                'effective_from' => $seeded->effective_from->toDateString(),
                'effective_to' => $seeded->effective_to->toDateString(),
            ])
            ->assertNotified('This cover cannot be set');

        expect(Delegation::query()->count())->toBe(1);
    });
});

it('keeps a cover that has finished on the list, reading as finished', function () {
    TenantContext::run($this->meridian, function () {
        Delegation::query()->create([
            'user_id' => meridianColleagueNamed('deepak')->getKey(),
            'delegate_id' => meridianColleagueNamed('anjali')->getKey(),
            'process_key' => 'exit',
            'effective_from' => now()->subDays(30)->toDateString(),
            'effective_to' => now()->subDays(16)->toDateString(),
        ]);

        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->assertCanSeeTableRecords(Delegation::query()->get())
            ->assertSee('Finished');
    });
});

it('changes the dates of a cover already set', function () {
    TenantContext::run($this->meridian, function () {
        $seeded = meridiansSeededCover();

        // Rakesh's fortnight away turns into three weeks.
        $lastDay = $seeded->effective_to->copy()->addWeek()->toDateString();

        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->callAction(TestAction::make('edit')->table($seeded), [
                'user_id' => $seeded->user_id,
                'delegate_id' => $seeded->delegate_id,
                'process_key' => $seeded->process_key,
                'effective_from' => $seeded->effective_from->toDateString(),
                'effective_to' => $lastDay,
            ])
            ->assertHasNoActionErrors();

        expect($seeded->fresh()->effective_to->toDateString())->toBe($lastDay);
    });
});

it('removes a cover that was set by mistake', function () {
    TenantContext::run($this->meridian, function () {
        $seeded = meridiansSeededCover();

        Livewire::actingAs(meridianColleagueNamed('chandni'))->test(ManageCover::class)
            ->callAction(TestAction::make('delete')->table($seeded));

        expect(Delegation::query()->count())->toBe(0);
    });
});
