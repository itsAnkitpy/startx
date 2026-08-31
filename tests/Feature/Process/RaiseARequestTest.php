<?php

use App\Filament\Pages\RaiseARequest;
use App\Models\CaseStep;
use App\Models\Delegation;
use App\Models\Designation;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AvailableSteps;
use App\Process\StepForm;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
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

it('offers each person only the processes whose first step is their own', function () {
    TenantContext::run($this->meridian, function () {
        // Meridian's hiring request names Anjali on its first step, and she holds no role
        // and has no queue of her own — which is the whole shape being checked: raising is
        // answered by the process, not by an action on somebody's role.
        expect(RaiseARequest::whatTheyCanRaise(theRaiserCalled('anjali'))->pluck('name')->all())
            ->toBe(['Hiring request']);

        // Rakesh approves hiring requests and clears exits all day and cannot raise a
        // thing, because no first step names him.
        expect(RaiseARequest::whatTheyCanRaise(theRaiserCalled('rakesh'))->pluck('name')->all())
            ->toBe([])
            // Nor can Meridian's own administrator, which is the point of there being no
            // permission for it.
            ->and(RaiseARequest::whatTheyCanRaise(theRaiserCalled('chandni'))->pluck('name')->all())
            ->toBe([]);
    });
});

it('never offers a process that is about a person', function () {
    TenantContext::run($this->meridian, function () {
        // Meridian's exit is live and its first step belongs to somebody. It is still not
        // offered, because a case about an employee has to be told which employee — and
        // an exit needs the leaver's last working day as well, which two legal clocks are
        // counted from. That screen is not this one.
        $exits = ProcessTemplate::query()
            ->where('subject_kind', 'employee')
            ->where('status', ProcessTemplate::Published)
            ->pluck('name');

        expect($exits)->not->toBeEmpty();

        foreach ([theRaiserCalled('anjali'), theRaiserCalled('rakesh'), theRaiserCalled('chandni')] as $person) {
            expect(RaiseARequest::whatTheyCanRaise($person)->pluck('name')->intersect($exits))->toBeEmpty();
        }
    });
});

it('lets whoever is covering somebody raise what that person raises', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = theRaiserCalled('anjali');
        $rohit = theRaiserCalled('rohit');

        // Rohit can raise nothing of his own.
        expect(RaiseARequest::whatTheyCanRaise($rohit)->pluck('name')->all())->toBe([]);

        // Anjali goes away and Rohit holds her hiring requests while she is gone.
        Delegation::factory()->covering($anjali, $rohit)->create(['process_key' => 'hiring_request']);

        expect(RaiseARequest::whatTheyCanRaise($rohit)->pluck('name')->all())
            ->toBe(['Hiring request'])
            // And Anjali still can. Cover is somebody added beside the person away, never
            // somebody put in their place.
            ->and(RaiseARequest::whatTheyCanRaise($anjali)->pluck('name')->all())
            ->toBe(['Hiring request']);
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
            ->toBe(['Hiring request']);
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
    // is what somebody would actually try. Rakesh is signed in and typing the address.
    $rakesh = TenantContext::run($this->meridian, fn () => theRaiserCalled('rakesh'));

    $this->actingAs($rakesh)->get(theRaiseAddress())->assertForbidden();
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
