<?php

use App\Authorization\PermissionResolver;
use App\Filament\Pages\RaiseARequest;
use App\Filament\Resources\Cases\CaseResource;
use App\Models\CaseStep;
use App\Models\Delegation;
use App\Models\Designation;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\StepForm;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| The screen a request is started from — the first thing in this product a person can
| create from a browser.
|
| Three claims are being checked, and they are the three the plan asks for. Somebody the
| process's first step does not name cannot raise one, and cannot reach the screen at all.
| The client's own rules are applied on the server, not only in the browser, which is the
| second writer leaning on the check the engine already does. And the salary threshold is
| frozen onto the case the moment it opens, so the figure the director's step is decided
| by is the figure that was true when the request was raised.
|
| They run against the demo company exactly as it is seeded, so what is checked is the
| screen Ankit opens rather than a fixture arranged to suit them.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody at the demo company, by first name. */
function theRaiserCalled(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** The hiring requests in flight, which is two before anybody raises a third. */
function requestsInFlight(): int
{
    return ProcessCase::query()->whereNull('subject_user_id')->count();
}

/** A complete set of answers to Meridian's hiring request. */
function aRequestFor(int $salary): array
{
    return [
        'department' => OrgUnit::query()->where('name', 'Shimla branch')->sole()->getKey(),
        'designation' => Designation::query()->where('name', 'Operations Officer')->sole()->getKey(),
        'replaces_a_leaver' => 'new_headcount',
        'positions' => 2,
        'annual_ctc' => $salary,
        'employment_type' => 'permanent',
        'target_start_date' => now()->addMonths(3)->format('Y-m-d'),
        'justification' => 'The Shimla desk is turning away work it cannot staff.',
    ];
}

/** The most recently raised request. */
function theNewestRequest(): ProcessCase
{
    return ProcessCase::query()->whereNull('subject_user_id')->orderByDesc('id')->first();
}

/** Meridian's two live exits, its real one and the one built with the mistake. */
const MeridiansExits = ['Exit', 'Exit (the mistake this check catches)'];

it('offers the hiring request only to whoever its first step names, and an exit to anybody who works here', function () {
    TenantContext::run($this->meridian, function () {
        // Meridian's hiring request names Anjali on its first step, and she holds no role
        // and has no queue of her own — which is the whole shape being checked: raising is
        // answered by the process, not by an action on somebody's role.
        expect(RaiseARequest::whatTheyCanRaise(theRaiserCalled('anjali'))->pluck('name')->all())
            ->toBe([...MeridiansExits, 'Hiring request']);

        // Rakesh approves hiring requests and clears exits all day, and no first step names
        // him, so the only thing he can raise is an exit — anybody who works here may resign.
        expect(RaiseARequest::whatTheyCanRaise(theRaiserCalled('rakesh'))->pluck('name')->all())
            ->toBe(MeridiansExits)
            ->and(RaiseARequest::whatTheyCanRaise(theRaiserCalled('chandni'))->pluck('name')->all())
            ->toBe(MeridiansExits);
    });
});

it('offers an exit to somebody with no job here only once they may change somebody\'s details', function () {
    TenantContext::run($this->meridian, function () {
        // An account whose job has not been recorded yet. There is nobody's exit it may
        // raise, its own included, so it is offered none.
        $newcomer = User::factory()->named('Kavya Rao')->create();

        expect(RaiseARequest::whatTheyCanRaise($newcomer)->pluck('name')->all())->toBe([])
            ->and(RaiseARequest::whoseExitTheyMayRaise($newcomer))->toBe([]);

        // Made HR head over Shimla, and the Shimla branch's exits are hers to raise.
        Role::query()->where('key', 'hr_head')->sole()->assignments()->create([
            'user_id' => $newcomer->getKey(),
            'org_unit_id' => OrgUnit::query()->where('name', 'Shimla branch')->sole()->getKey(),
            'includes_descendants' => false,
        ]);
        app(PermissionResolver::class)->forget();

        expect(RaiseARequest::whatTheyCanRaise($newcomer)->pluck('name')->all())->toBe(MeridiansExits);
    });
});

it('lists whose exit each person may raise, themselves first', function () {
    TenantContext::run($this->meridian, function () {
        // Anjali holds no role, so only her own.
        expect(RaiseARequest::whoseExitTheyMayRaise(theRaiserCalled('anjali')))
            ->toBe([theRaiserCalled('anjali')->getKey() => 'You (Anjali Rao)']);

        // Rakesh is HR head over Shimla: his own, then the Shimla branch. Not Rohit in
        // Pune, and not Chandni, who sits above the branch.
        expect(array_values(RaiseARequest::whoseExitTheyMayRaise(theRaiserCalled('rakesh'))))
            ->toBe(['You (Rakesh Menon)', 'Anjali Rao', 'Deepak Iyer', 'Priya Nair']);

        // Chandni administers the whole company, so everybody.
        expect(array_values(RaiseARequest::whoseExitTheyMayRaise(theRaiserCalled('chandni'))))
            ->toBe(['You (Chandni Verma)', 'Anjali Rao', 'Deepak Iyer', 'Priya Nair', 'Rakesh Menon', 'Rohit Menon']);
    });
});

it('lets whoever is covering somebody raise what that person raises', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = theRaiserCalled('anjali');
        $rohit = theRaiserCalled('rohit');

        // Rohit can raise nothing but his own exit.
        expect(RaiseARequest::whatTheyCanRaise($rohit)->pluck('name')->all())->toBe(MeridiansExits);

        // Anjali goes away and Rohit holds her hiring requests while she is gone.
        Delegation::factory()->covering($anjali, $rohit)->create(['process_key' => 'hiring_request']);

        expect(RaiseARequest::whatTheyCanRaise($rohit)->pluck('name')->all())
            ->toBe([...MeridiansExits, 'Hiring request'])
            // And Anjali still can. Cover is somebody added beside the person away, never
            // somebody put in their place.
            ->and(RaiseARequest::whatTheyCanRaise($anjali)->pluck('name')->all())
            ->toBe([...MeridiansExits, 'Hiring request']);
    });
});

it('asks barely more to draw the menu when a client runs twenty more processes', function () {
    TenantContext::run($this->meridian, function () {
        $this->actingAs(theRaiserCalled('anjali'));

        $asked = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            RaiseARequest::canAccess();
            $questions = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $questions;
        };

        $withMeridiansOwn = $asked();

        // Twenty more live processes, every one of them opening with a step held by the
        // client's HR heads — which is what a real client's processes mostly look like. The
        // menu is drawn on every screen in the signed-in area, so this is the price of
        // every page load, not of one page.
        for ($extra = 0; $extra < 20; $extra++) {
            $process = ProcessTemplate::factory()->named('extra_'.$extra, 'Extra '.$extra)
                ->about('none')->create();

            ProcessStep::factory()->of($process)->at(1, 1)->named('Ask')->offering('approved')
                ->create(['assignee_rule' => ['kind' => 'role_global', 'role' => 'hr_head']]);

            $process->publish();
        }

        // Named "Extra", so all twenty are looked at before the hiring request Anjali can
        // actually raise — the worst order there is rather than a flattering one.
        expect(RaiseARequest::whatTheyCanRaise(theRaiserCalled('anjali'))->pluck('name')->all())
            ->toBe([...MeridiansExits, 'Hiring request']);

        // Who holds a role is asked once and then remembered for the rest of the walk, so
        // twenty more processes pointing at the same role cost a handful of questions
        // rather than four each.
        expect($asked())->toBeLessThanOrEqual($withMeridiansOwn + 4);
    });
});

it('stops the menu question at the first thing a person can raise', function () {
    TenantContext::run($this->meridian, function () {
        $this->actingAs(theRaiserCalled('anjali'));

        // Named so that Meridian's hiring request, the one Anjali can raise, is reached
        // first and the twenty behind it never need looking at.
        for ($extra = 0; $extra < 20; $extra++) {
            $process = ProcessTemplate::factory()->named('later_'.$extra, 'Zzz '.$extra)
                ->about('none')->create();

            ProcessStep::factory()->of($process)->at(1, 1)->named('Ask')->offering('approved')
                ->create(['assignee_rule' => ['kind' => 'role_global', 'role' => 'hr_head']]);

            $process->publish();
        }

        $asked = function (callable $question): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $question();
            $questions = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $questions;
        };

        // Looked up before either measurement, because a lookup inside one of them would
        // be counted as part of it and the comparison would flatter whichever side had it.
        $anjali = theRaiserCalled('anjali');

        // Drawing the link only needs to know there is something. Filling the page needs
        // the whole list, and costs more — which is the difference being claimed.
        expect($asked(fn () => RaiseARequest::canAccess()))
            ->toBeLessThan($asked(fn () => RaiseARequest::whatTheyCanRaise($anjali)));
    });
});

it('never offers a process whose first step cannot be approved', function () {
    TenantContext::run($this->meridian, function () {
        // A process whose opening step can only be turned down. Raising one is answering
        // that step and approving it, so this one would take the whole form and then
        // refuse every press. Anjali is named on it and still never sees it.
        $awkward = ProcessTemplate::factory()->named('kit_request', 'Kit request')->about('none')->create();

        ProcessStep::factory()->of($awkward)->at(1, 1)->named('Ask')
            ->heldBy('anjali@meridian.test')->offering('rejected')->create();

        $awkward->publish();

        expect(RaiseARequest::whatTheyCanRaise(theRaiserCalled('anjali'))->pluck('name')->all())
            ->toBe([...MeridiansExits, 'Hiring request']);
    });
});

/** The raise screen on Meridian's own subdomain, which is how anybody reaches it. */
function theRaiseAddress(): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/raise-a-request';
}

it('serves the raise screen to somebody a first step names', function () {
    // The whole way in, exactly as Ankit will: the company's own subdomain puts Meridian
    // in scope and the panel serves the screen.
    $anjali = TenantContext::run($this->meridian, fn () => theRaiserCalled('anjali'));

    $this->actingAs($anjali)->get(theRaiseAddress())->assertOk()->assertSee('Hiring request');
});

it('refuses the raise address itself to somebody with nothing to raise', function () {
    // Asking the screen's own check is not the same as knocking on the door, and the door
    // is what somebody would actually try. An account whose job has not been recorded yet
    // is signed in and typing the address: no first step names it, and it has no job to
    // leave.
    $newcomer = TenantContext::run($this->meridian, fn () => User::factory()->named('Kavya Rao')->create());

    $this->actingAs($newcomer)->get(theRaiseAddress())->assertForbidden();
});

it('draws the client own questions and nothing written into the page', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole();

        $page = Livewire::actingAs(theRaiserCalled('anjali'))->test(RaiseARequest::class)
            ->set('processId', $hiring->getKey())
            ->assertOk();

        // Every question on the client's own form, in the words the client called it by,
        // read off their rows rather than off a list written into this test.
        $asked = (new StepForm)->labels($hiring->steps()->first());

        expect($asked)->toHaveCount(8);

        foreach ($asked as $label) {
            $page->assertSee($label);
        }

        // And nothing on the page names a question, which is what makes a client's own
        // edit to their form appear here on its own.
        expect(file_get_contents(app_path('Filament/Pages/RaiseARequest.php')))
            ->not->toContain('annual_ctc')
            ->and(file_get_contents(resource_path('views/filament/pages/raise-a-request.blade.php')))
            ->not->toContain('annual_ctc');
    });
});

/**
 * A second live process Anjali may raise: one question, one step, about nobody.
 *
 * Built rather than seeded, because Meridian runs one request today and what is being
 * checked needs two — the list is a real choice, and changing that choice cannot leave the
 * previous process's answers sitting in boxes nobody can see any more.
 */
function anotherRequestAnjaliCanRaise(): ProcessTemplate
{
    $form = FormDefinition::factory()->named('desk_request', 'Desk request')->create();

    FormField::factory()->on($form)->at(1)->required()
        ->asking('desk', 'Which desk', FormField::Text)->create();

    $form->publish();

    $process = ProcessTemplate::factory()->named('desk_request', 'Desk request')->about('none')->create();

    ProcessStep::factory()->of($process)->at(1, 1)->named('Ask for a desk')
        ->asking($form)->heldBy('anjali@meridian.test')->offering('approved')->create();

    $process->publish();

    return $process;
}

it('empties the form when somebody changes what they are raising', function () {
    TenantContext::run($this->meridian, function () {
        $desks = anotherRequestAnjaliCanRaise();
        $hiring = ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole();

        Livewire::actingAs(theRaiserCalled('anjali'))->test(RaiseARequest::class)
            // Both are on the list, so the choice is a real one.
            ->assertSee('Hiring request')
            ->assertSee('Desk request')
            ->set('processId', $hiring->getKey())
            ->set('answers', aRequestFor(900000))
            ->set('processId', $desks->getKey())
            // The answers belonged to questions this process does not ask, and sending them
            // with it would be refused in the name of boxes nobody can see any more.
            ->assertSet('answers', [])
            ->assertSee('Which desk');
    });
});

it('refuses a request with a required answer missing, and opens nothing', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole();

        $before = requestsInFlight();

        $answers = aRequestFor(900000);
        unset($answers['justification']);

        Livewire::actingAs(theRaiserCalled('anjali'))->test(RaiseARequest::class)
            ->set('processId', $hiring->getKey())
            ->set('answers', $answers)
            ->call('raise')
            // Beside the box it is about, and in the client's own words for the question.
            ->assertHasErrors(['answers.justification'])
            ->assertSee('Why the role is needed');

        expect(requestsInFlight())->toBe($before);
    });
});

it('raises a request that goes to the branch the request itself names', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole();

        $before = requestsInFlight();
        $anjali = theRaiserCalled('anjali');

        Livewire::actingAs($anjali)->test(RaiseARequest::class)
            ->set('processId', $hiring->getKey())
            ->set('answers', aRequestFor(1200000))
            ->call('raise')
            ->assertHasNoErrors()
            ->assertOk()
            // The boxes are emptied so a second request is not raised with the first one's
            // answers still in them, and the process stays chosen.
            ->assertSet('answers', [])
            ->assertSet('processId', $hiring->getKey());

        expect(requestsInFlight())->toBe($before + 1);

        $raised = theNewestRequest();

        // The first step is answered and closed by the raising, in Anjali's name, with the
        // answers she typed.
        $first = CaseStep::query()->where('case_id', $raised->getKey())->where('sequence', 1)->sole();

        expect($first->outcome)->toBe('approved')
            ->and((int) $first->assignee_id)->toBe((int) $anjali->getKey())
            ->and($raised->answersSoFar())->toEqual(aRequestFor(1200000));

        // And it is now waiting on the head of the branch the request named, worked out
        // from the answer rather than from a job row the case does not have.
        expect((new AvailableSteps)->waitingOn(theRaiserCalled('rakesh'))
            ->contains(fn ($waiting) => (int) $waiting->case->getKey() === (int) $raised->getKey()))
            ->toBeTrue();
    });
});

it('freezes the salary threshold onto the case as it opens', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole();

        Livewire::actingAs(theRaiserCalled('anjali'))->test(RaiseARequest::class)
            ->set('processId', $hiring->getKey())
            ->set('answers', aRequestFor(2400000))
            ->call('raise')
            ->assertHasNoErrors();

        // Fifteen lakh is the figure Meridian has never changed. It is written onto the
        // case, so a client raising the threshold tomorrow moves the next request and
        // leaves this one deciding on the figure that was true when it was raised.
        expect(theNewestRequest()->settings_snapshot)
            ->toEqual(['hiring_director_threshold' => 1500000]);
    });
});

it('leaves nothing behind when the engine refuses what the screen sent', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::query()->where('key', 'hiring_request')
            ->where('status', ProcessTemplate::Published)->sole();

        $before = requestsInFlight();

        // An answer to a question no version of this form ever asked — somebody reaching
        // past the screen. The engine refuses it, and the case opened a moment earlier has
        // to go with it: an empty request nobody raised and nobody can finish would
        // otherwise be left in the client's list, one for every press.
        Livewire::actingAs(theRaiserCalled('anjali'))->test(RaiseARequest::class)
            ->set('processId', $hiring->getKey())
            ->set('answers', [...aRequestFor(900000), 'signing_bonus' => 50000])
            ->call('raise')
            ->assertOk();

        expect(requestsInFlight())->toBe($before);
    });
});

/*
| Raising an exit — module 07, step 1.
|
| An exit answers no step when it is raised, because nobody answers a step on their own
| case. It opens with who is leaving, their last working day, how their employment is
| ending, and the personal address everything owed to them goes to once they have gone.
*/

/** Meridian's real exit process. */
function meridiansLiveExit(): ProcessTemplate
{
    return ProcessTemplate::query()->where('key', 'exit')->where('status', ProcessTemplate::Published)->sole();
}

/**
 * Somebody whose seeded exit has been withdrawn, so a new one can be raised for them.
 * Everybody at Meridian bar Chandni has an exit running in the demo.
 */
function withTheirExitWithdrawn(string $first): User
{
    $person = theRaiserCalled($first);

    ProcessCase::query()->where('subject_user_id', $person->getKey())
        ->whereNull('closed_at')->whereNull('cancelled_at')->get()
        ->each(fn (ProcessCase $case) => (new CaseEngine)->cancel($case, theRaiserCalled('chandni'), 'Withdrew the resignation'));

    return $person;
}

/** The newest exit about somebody that has not been withdrawn. */
function theNewestExitAbout(User $person): ?ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', $person->getKey())
        ->whereNull('cancelled_at')->latest('id')->first();
}

it('raises your own exit as a resignation, and keeps its page from you', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = withTheirExitWithdrawn('deepak');
        $lastDay = now()->addDays(30)->toDateString();

        Livewire::actingAs($deepak)->test(RaiseARequest::class)
            ->set('processId', meridiansLiveExit()->getKey())
            // It starts on his own exit, with the address on file in the box to confirm.
            ->assertSet('leaverId', $deepak->getKey())
            ->assertSet('personalEmail', 'deepak@personal.example')
            ->assertSee('Your own exit is recorded as a resignation.')
            ->assertDontSee('How their employment is ending')
            // Reaching past the screen to say otherwise changes nothing.
            ->set('employmentEndedBy', 'disablement')
            ->set('lastWorkingDay', $lastDay)
            ->call('raise')
            ->assertHasNoErrors()
            // Told where everything about it goes, and left on an empty form rather than
            // sent to a page that is not his to read.
            ->assertNotified('Your exit is raised.')
            ->assertNoRedirect()
            ->assertSet('processId', null);

        $raised = theNewestExitAbout($deepak);

        expect($deepak->can('view', $raised))->toBeFalse();

        // Two years in and resigning, so both deadlines are worked out and only the
        // settlement one is owed.
        expect($raised->employment_ended_by)->toBe('resignation')
            ->and((int) $raised->initiated_by)->toBe((int) $deepak->getKey())
            ->and($raised->statutory_from->toDateString())->toBe($lastDay)
            ->and($raised->statutory_due_at)->not->toBeNull()
            ->and($raised->gratuity_due_at)->toBeNull();

        // And no step was answered: it waits on Rakesh, his manager.
        expect((new AvailableSteps)->waitingOn(theRaiserCalled('rakesh'))
            ->contains(fn ($waiting) => $waiting->case->is($raised)))->toBeTrue()
            ->and(CaseStep::query()->where('case_id', $raised->getKey())->count())->toBe(0);
    });
});

it('raises somebody else\'s exit with how it is ending, and corrects their address', function () {
    TenantContext::run($this->meridian, function () {
        $deepak = withTheirExitWithdrawn('deepak');
        $rakesh = theRaiserCalled('rakesh');

        Livewire::actingAs($rakesh)->test(RaiseARequest::class)
            ->set('processId', meridiansLiveExit()->getKey())
            ->set('leaverId', $deepak->getKey())
            ->assertSet('personalEmail', 'deepak@personal.example')
            ->assertSee('How their employment is ending')
            ->set('lastWorkingDay', now()->addDays(10)->toDateString())
            ->set('personalEmail', 'deepak.iyer@example.net')
            // Asked of somebody raising another person's, because the gratuity rule reads it.
            ->call('raise')
            ->assertHasErrors(['employmentEndedBy' => 'required'])
            ->set('employmentEndedBy', 'dismissal')
            ->call('raise')
            ->assertHasNoErrors()
            ->assertRedirect(CaseResource::getUrl('view', ['record' => theNewestExitAbout($deepak)]));

        $raised = theNewestExitAbout($deepak);

        expect($raised->employment_ended_by)->toBe('dismissal')
            ->and((int) $raised->initiated_by)->toBe((int) $rakesh->getKey())
            ->and($deepak->fresh()->personal_email)->toBe('deepak.iyer@example.net');
    });
});

it('refuses an exit with no personal email, and opens nothing', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = withTheirExitWithdrawn('anjali');

        $page = Livewire::actingAs($anjali)->test(RaiseARequest::class)
            ->set('processId', meridiansLiveExit()->getKey())
            ->set('lastWorkingDay', now()->addDays(30)->toDateString())
            ->set('personalEmail', '   ')
            ->call('raise')
            ->assertHasErrors(['personalEmail' => 'required'])
            ->assertSee('Give a personal email.');

        $page->set('personalEmail', 'not an address')
            ->call('raise')
            ->assertHasErrors(['personalEmail' => 'email']);

        expect(theNewestExitAbout($anjali))->toBeNull()
            ->and($anjali->fresh()->personal_email)->toBe('anjali@personal.example');
    });
});

it('refuses an exit for somebody outside the part of the company you look after', function () {
    TenantContext::run($this->meridian, function () {
        $rohit = withTheirExitWithdrawn('rohit');

        // Rakesh is HR head over Shimla and Rohit works in Pune. Sent from past the list,
        // nothing about Rohit reaches the box and nothing is raised.
        Livewire::actingAs(theRaiserCalled('rakesh'))->test(RaiseARequest::class)
            ->set('processId', meridiansLiveExit()->getKey())
            ->set('leaverId', $rohit->getKey())
            ->assertSet('personalEmail', null)
            ->set('lastWorkingDay', now()->addDays(10)->toDateString())
            ->set('employmentEndedBy', 'resignation')
            ->set('personalEmail', 'rohit.new@example.net')
            ->call('raise')
            ->assertHasErrors(['leaverId' => 'in']);

        expect(theNewestExitAbout($rohit))->toBeNull()
            ->and($rohit->fresh()->personal_email)->toBe('rohit@personal.example');
    });
});

it('refuses a second exit for somebody whose exit is open, and leaves their address as it was', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = theRaiserCalled('anjali');
        $open = theNewestExitAbout($anjali);

        Livewire::actingAs(theRaiserCalled('rakesh'))->test(RaiseARequest::class)
            ->set('processId', meridiansLiveExit()->getKey())
            ->set('leaverId', $anjali->getKey())
            ->set('lastWorkingDay', now()->addDays(10)->toDateString())
            ->set('employmentEndedBy', 'resignation')
            ->set('personalEmail', 'anjali.rao@example.net')
            ->call('raise')
            ->assertNotified('Anjali Rao already has an exit open. It has to finish or be withdrawn before another one can be raised.')
            ->assertNoRedirect();

        expect(theNewestExitAbout($anjali)->is($open))->toBeTrue()
            ->and($anjali->fresh()->personal_email)->toBe('anjali@personal.example');
    });
});
