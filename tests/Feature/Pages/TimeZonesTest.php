<?php

use App\Filament\Pages\MyQueue;
use App\Filament\Resources\Cases\Pages\ListCases;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RelationManagers\WhoHoldsItRelationManager;
use App\Models\CaseStep;
use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Process\SubjectPanel;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Livewire\Livewire;

/*
| Every moment a client reads is on the reader's own clock, else their company's. Stored
| times stay UTC. A date with no time of day is never moved.
|
| The moment used throughout is 20:00 UTC on 1 December: half past one in the morning on
| 2 December in India, and three in the afternoon on 1 December in New York. A screen
| reading UTC and a screen reading India's clock show different days.
*/

const TheOneMoment = '2026-12-01 20:00:00';

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

function readerAtMeridian(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/**
 * Rakesh's exit, with the day it opened, every answer on it and every message about it
 * moved to the one moment. Its trail of events is add-only and cannot be moved, which
 * is why the sentences built from that trail have a test of their own below.
 *
 * His last working day is moved to that moment's own day in India too, and his settlement
 * deadline with it, because the demo counts them from today and one of them could land on
 * the very date the checks below say must not appear.
 */
function rakeshsExitAtTheOneMoment(): ProcessCase
{
    $exit = ProcessCase::query()
        ->whereRelation('subject', 'work_email', 'rakesh@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->sole();

    ProcessCase::query()->whereKey($exit->getKey())->update([
        'opened_at' => TheOneMoment,
        'statutory_from' => '2026-12-02',
        'statutory_due_at' => '2026-12-04',
    ]);
    CaseStep::query()->where('case_id', $exit->getKey())->whereNotNull('acted_at')->update(['acted_at' => TheOneMoment]);
    NotificationDelivery::query()->where('case_id', $exit->getKey())->update(['created_at' => TheOneMoment, 'sent_at' => TheOneMoment]);

    return $exit->fresh();
}

it('shows every moment on a case on the reader company clock rather than UTC', function () {
    TenantContext::run($this->meridian, function () {
        $exit = rakeshsExitAtTheOneMoment();
        $chandni = readerAtMeridian('chandni');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $exit->getKey()])
            ->assertOk()
            // Filament's own: when each message went out.
            ->assertSee('2 December 2026, 01:30')
            // A moment shown as a day, which Filament would read on UTC's calendar.
            ->assertSeeInOrder(['Started', '2 December 2026'])
            // Our own sentence about each answer.
            ->assertSee('Approved by Priya Nair on 2 December 2026')
            ->assertDontSee('1 December 2026')
            ->assertDontSee('Dec 1, 2026');

        Livewire::actingAs($chandni)->test(ListCases::class)
            ->assertOk()
            ->assertSee('Dec 2, 2026')
            ->assertDontSee('Dec 1, 2026');

        // The panel about the person, which the queue screen draws above a decision.
        expect((new SubjectPanel)->of($exit)['asOf'])
            ->toBe('Job details as they were when this case opened on 2 December 2026. The last working day and the legal deadline are as they stand today.');
    });
});

it('dates a send-back and a handover on the reader company clock', function () {
    TenantContext::run($this->meridian, function () {
        $this->travelTo(TheOneMoment);

        $rakesh = readerAtMeridian('rakesh');
        $chandni = readerAtMeridian('chandni');
        [$sentBack, $handedOn] = ProcessCase::query()
            ->whereRelation('template', 'key', 'hiring_request')
            ->whereNull('closed_at')
            ->orderBy('number')
            ->take(2)
            ->get();

        // Rakesh sends one request back and Anjali answers it again, so the send-back is
        // told from the case's trail rather than from the step.
        Livewire::actingAs($rakesh)->test(MyQueue::class)
            ->call('askFor', $sentBack->getKey(), 2, 'sent_back')
            ->set("reasons.{$sentBack->getKey()}.2", 'The start date is before the vacancy exists.')
            ->call('decide', $sentBack->getKey(), 2, 'sent_back')
            ->assertHasNoErrors();

        Livewire::actingAs(readerAtMeridian('anjali'))->test(MyQueue::class)
            ->set("answers.{$sentBack->getKey()}.1.justification", 'Corrected.')
            ->call('decide', $sentBack->getKey(), 1, 'approved')
            ->assertHasNoErrors();

        // And Rakesh's open approval on the other moves to Priya when his own exit settles
        // who takes his work on.
        (new CaseEngine)->claim($handedOn, 2, $rakesh);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => ProcessCase::query()
            ->whereRelation('subject', 'work_email', 'rakesh@meridian.test')
            ->whereRelation('template', 'key', 'exit')
            ->sole()
            ->getKey()])
            // The day is left as the form offers it, which is today on Chandni's calendar.
            ->callAction('handOverTheirWork', [
                'successor' => readerAtMeridian('priya')->getKey(),
            ])
            ->assertHasNoActionErrors();

        expect(Succession::query()->where('from_user_id', $rakesh->getKey())->sole()->effective_at->toDateString())
            ->toBe('2026-12-02');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $sentBack->getKey()])
            ->assertSee('Sent back by Rakesh Menon on 2 December 2026')
            ->assertDontSee('1 December 2026');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $handedOn->getKey()])
            ->assertSee('Moved from Rakesh Menon to Priya Nair on 2 December 2026');
    });
});

it('shows a moment in the reader own time zone ahead of their company one', function () {
    TenantContext::run($this->meridian, function () {
        $exit = rakeshsExitAtTheOneMoment();
        $chandni = readerAtMeridian('chandni');
        $chandni->update(['timezone' => 'America/New_York']);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $exit->getKey()])
            ->assertOk()
            ->assertSee('1 December 2026, 15:00')
            ->assertSee('Approved by Priya Nair on 1 December 2026');
    });
});

it('dates a role grant on the reader own clock', function () {
    TenantContext::run($this->meridian, function () {
        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        RoleAssignment::query()->where('role_id', $administrator->getKey())->update(['created_at' => TheOneMoment]);

        Livewire::actingAs(readerAtMeridian('anjali'))->test(WhoHoldsItRelationManager::class, [
            'ownerRecord' => $administrator,
            'pageClass' => EditRole::class,
        ])
            ->assertOk()
            ->assertSee('Dec 2, 2026')
            ->assertDontSee('Dec 1, 2026');
    });
});

it('never moves a date with no time of day, even for a company behind UTC', function () {
    // India is ahead of UTC, so a date read as midnight UTC is still the same day there,
    // and a test on India's clock passes whether a plain date is moved or not. New York
    // is behind, so a moved date shows as the day before.
    $this->meridian->update(['timezone' => 'America/New_York']);

    TenantContext::run($this->meridian, function () {
        $exit = rakeshsExitAtTheOneMoment();
        $this->actingAs(readerAtMeridian('chandni'));

        expect((new SubjectPanel)->of($exit)['facts']['Joined'])
            ->toBe($exit->subjectEmploymentRecord->joining_date->format('j F Y'));
    });
});
