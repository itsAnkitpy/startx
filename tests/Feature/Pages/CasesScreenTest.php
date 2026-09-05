<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\ProcessRefused;
use App\Filament\Pages\MyQueue;
use App\Filament\Pages\RaiseARequest;
use App\Filament\Resources\Cases\Pages\ListCases;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\ProcessCase;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Process\CaseHistory;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;
use Livewire\Livewire;

/*
| The Cases screen as a client uses it: a list they narrow, and a case they open.
|
| The file next door owns what the history says. This one owns the screen around it — the
| number a client quotes, the two filters, the search, and the one thing that can be done
| from a case: settling who takes on the work of somebody who has left.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoUsesTheCasesScreen(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

/** Rakesh's own exit, which is where his work is handed on from. */
function theSeededExitOf(string $first): ProcessCase
{
    return ProcessCase::query()
        ->whereRelation('subject', 'work_email', $first.'@meridian.test')
        ->whereRelation('template', 'key', 'exit')
        ->sole();
}

/*
| The number a client quotes on the phone
*/

it('numbers a client cases from one, whatever the platform has run for anybody else', function () {
    // Meridian is set up first and runs six cases, so Vertex's rows get keys well past
    // one. What a client used to be shown was that key: their first four cases could read
    // #7, #11, #12 and #26, and the gaps say roughly how busy every other client is.
    $this->seed(VertexSeeder::class);

    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    $numbersAt = fn (Tenant $client): array => TenantContext::run($client, fn (): array => ProcessCase::query()
        ->orderBy('number')->pluck('number')->all());

    $meridians = $numbersAt($this->meridian);
    $vertexs = $numbersAt($vertex);

    expect($meridians)->toBe(range(1, count($meridians)))
        ->and($vertexs)->toBe(range(1, count($vertexs)));

    // And Vertex's own first case is genuinely not its row's key, which is the whole
    // point: two clients both count from one, on one table.
    TenantContext::run($vertex, function () use ($meridians) {
        $theirFirst = ProcessCase::query()->where('number', 1)->sole();

        expect((int) $theirFirst->getKey())->toBeGreaterThan(count($meridians));
    });
});

it('renumbers the cases a client already had when the number arrives', function () {
    // The half that only runs on a database with rows already in it: Ankit's own, and every
    // client already using the product. Proved by taking the column away and putting it
    // back, which is exactly what the migration does on a live database.
    $this->seed(VertexSeeder::class);

    $migration = require database_path('migrations/2026_09_03_140000_number_each_client_cases_from_one.php');

    $migration->down();
    $migration->up();

    $numbersInOpeningOrder = fn (string $slug): array => TenantContext::run(
        Tenant::query()->where('slug', $slug)->sole(),
        fn (): array => ProcessCase::query()->orderBy('id')->pluck('number')->all(),
    );

    $meridians = $numbersInOpeningOrder(MeridianSeeder::Slug);
    $vertexs = $numbersInOpeningOrder(VertexSeeder::Slug);

    // Each client counted from one in the order their own cases opened, rather than one
    // run of numbering shared across the platform.
    expect($meridians)->toBe(range(1, count($meridians)))
        ->and($vertexs)->toBe(range(1, count($vertexs)))
        ->and(count($meridians))->toBeGreaterThan(0)
        ->and(count($vertexs))->toBeGreaterThan(0);
});

it('numbers a case from its own client, even where the client narrowing is switched off', function () {
    $this->seed(VertexSeeder::class);

    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    [$theirProcess, $theirHighest] = TenantContext::run($vertex, fn (): array => [
        ProcessTemplate::query()->where('status', ProcessTemplate::Published)->firstOrFail(),
        (int) ProcessCase::query()->max('number'),
    ]);

    // Meridian in scope, and the audited path that reaches every client at once switched
    // on — which is exactly how both of this step's migrations run. A case belonging to
    // Vertex opened there must still count from Vertex's own highest, not from the highest
    // on the platform, or the number hands one client a measure of everybody else's work.
    $opened = TenantContext::run($this->meridian, fn (): ProcessCase => TenantContext::cross(
        function () use ($vertex, $theirProcess): ProcessCase {
            $case = new ProcessCase([
                'template_id' => $theirProcess->getKey(),
                'opened_at' => CarbonImmutable::now(),
            ]);

            $case->tenant_id = $vertex->getKey();
            $case->save();

            return $case;
        },
        'opening a case belonging to a client who is not the one in scope',
    ));

    expect((int) $opened->number)->toBe($theirHighest + 1);
});

it('carries on counting from the client own highest number when a new case opens', function () {
    TenantContext::run($this->meridian, function () {
        $anjali = whoUsesTheCasesScreen('anjali');
        $highest = (int) ProcessCase::query()->max('number');

        Livewire::actingAs($anjali)->test(RaiseARequest::class)
            ->assertOk();

        $opened = (new CaseEngine)->open(
            ProcessTemplate::query()->where('key', 'hiring_request')
                ->where('status', ProcessTemplate::Published)->sole(),
            by: $anjali,
        );

        expect((int) $opened->number)->toBe($highest + 1);
    });
});

it('puts the number in front of the case on the list and at the top of its own page', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $exit = theSeededExitOf('rakesh');

        $list = Livewire::actingAs($chandni)->test(ListCases::class)
            ->assertOk()
            ->assertSee('#'.$exit->number);

        // The row goes to the case's own page rather than opening a pop-up over the list:
        // the whole story of a case does not belong in a modal.
        expect($list->instance()->getTable()->getRecordUrl($exit))
            ->toEndWith('/admin/cases/'.$exit->getKey());

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $exit->getKey()])
            ->assertOk()
            ->assertSee('#'.$exit->number.' · Rakesh Menon');
    });
});

it('opens both addresses in the panel itself, not only as a component', function () {
    [$chandni, $exit] = TenantContext::run($this->meridian, fn (): array => [
        whoUsesTheCasesScreen('chandni'),
        theSeededExitOf('rakesh'),
    ]);

    // Through the front door, with the client resolved off the address and the panel's own
    // middleware in the way, because a component that renders in a test is not yet a page
    // somebody can open.
    $this->actingAs($chandni)
        ->get('http://meridian.localhost/admin/cases')
        ->assertOk()
        ->assertSee('Cases');

    $this->actingAs($chandni)
        ->get('http://meridian.localhost/admin/cases/'.$exit->getKey())
        ->assertOk()
        ->assertSee('Step by step');
});

it('shuts a case at its own address to somebody the list kept it from', function () {
    [$anjali, $somebodyElsesExit] = TenantContext::run($this->meridian, fn (): array => [
        whoUsesTheCasesScreen('anjali'),
        theSeededExitOf('deepak'),
    ]);

    // Anjali holds no role at all and raises every hiring request, so her own requests are
    // the whole of what she is shown. Typing somebody else's case straight into the address
    // bar is how a list gets walked round, and this is what shuts it: the address is
    // resolved through the same narrowing the list uses, so a case kept off her list cannot
    // be found by its number either. The record's own check refuses it a second time, and
    // that half cannot be proved from here — take it out and this still passes — because
    // the narrowing has already turned the address away before it is asked.
    $this->actingAs($anjali)
        ->get('http://meridian.localhost/admin/cases/'.$somebodyElsesExit->getKey())
        ->assertNotFound();
});

it('says a step waiting on somebody with no login was sent to them, not picked up by them', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');

        // Rakesh confirms his own handover at his personal address, through a link, because
        // a leaver's sign-in is switched off. Nobody has picked that step up — there is no
        // account holding it at all — and saying somebody had put the case on their desk.
        $step = collect((new CaseHistory)->stepByStep(theSeededExitOf('rakesh')))
            ->firstWhere('sequence', 4);

        expect($step['said'])->toBe('Sent to Rakesh Menon to answer through a link. Not answered yet.');

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => theSeededExitOf('rakesh')->getKey()])
            ->assertOk()
            ->assertSee('Sent to Rakesh Menon to answer through a link.')
            ->assertDontSee('Picked up by Rakesh Menon');
    });
});

/*
| Narrowing the list
*/

it('narrows the list to one process, and to one state', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');

        $exits = ProcessCase::query()->whereRelation('template', 'key', 'exit')->get();
        $requests = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')->get();

        // Four exits and two hiring requests in one scroll until now, with nothing to
        // separate them.
        Livewire::actingAs($chandni)->test(ListCases::class)
            ->filterTable('process', 'exit')
            ->assertCanSeeTableRecords($exits)
            ->assertCanNotSeeTableRecords($requests);

        // Nothing at Meridian has finished, so asking for the finished ones asks for
        // nothing — which is a real answer and used to be impossible to ask for.
        Livewire::actingAs($chandni)->test(ListCases::class)
            ->filterTable('state', 'running')
            ->assertCanSeeTableRecords($exits)
            ->filterTable('state', 'finished')
            ->assertCanNotSeeTableRecords($exits);
    });
});

it('finds a case by the number a client was given', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $wanted = theSeededExitOf('rakesh');

        $others = ProcessCase::query()->whereKeyNot($wanted->getKey())
            ->where('number', '!=', $wanted->number)->get();

        Livewire::actingAs($chandni)->test(ListCases::class)
            ->searchTable((string) $wanted->number)
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords($others);

        // And typed back exactly as the screen gives it to them. The hash is painted on
        // rather than stored, so a client quoting "#4" down the phone and typing it in
        // found nothing at all.
        Livewire::actingAs($chandni)->test(ListCases::class)
            ->searchTable('#'.$wanted->number)
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords($others);
    });
});

it('finds a case by the name of the person it is about', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');

        Livewire::actingAs($chandni)->test(ListCases::class)
            ->searchTable('Rohit')
            ->assertCanSeeTableRecords([theSeededExitOf('rohit')])
            ->assertCanNotSeeTableRecords([theSeededExitOf('anjali')]);
    });
});

/*
| An approval somebody gave while covering for a colleague
|
| The engine has recorded whose approval it was since cover existed, and no screen read it
| back — so an approval Priya gave on Rakesh's behalf read on the case a year later as an
| approval Priya gave. The queue card says it before she answers; this is the other half.
*/

it('says on the case that an approval was given by somebody covering for a colleague', function () {
    TenantContext::run($this->meridian, function () {
        $priya = whoUsesTheCasesScreen('priya');

        // The demo seeds Priya covering Rakesh's hiring approvals for a fortnight round
        // today, so both requests waiting on him reach her marked as his.
        $request = ProcessCase::query()
            ->whereRelation('template', 'key', 'hiring_request')
            ->whereNull('closed_at')
            ->firstOrFail();

        Livewire::actingAs($priya)->test(MyQueue::class)
            ->call('decide', $request->getKey(), 2, 'approved')
            ->assertHasNoErrors();

        $approval = collect((new CaseHistory)->stepByStep($request->fresh()))->firstWhere('sequence', 2);

        expect($approval['said'])->toContain('Approved by Priya Nair')
            ->toContain('behalf while covering for them');

        Livewire::actingAs($priya)->test(ViewCase::class, ['record' => $request->getKey()])
            ->assertOk()
            ->assertSee('behalf while covering for them');
    });
});

/*
| Settling who takes on a leaver's work
*/

it('shows what the successor inherits before anybody confirms anything', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $rakeshsExit = theSeededExitOf('rakesh');

        // Four approvals waiting on him — two hiring requests and the HR clearance on two
        // colleagues' exits — two people reporting to him, and two roles over the Shimla
        // branch. **Nobody has opened any of those four**, so a count taken from the rows
        // people happen to have picked up would have said none, and missed exactly the work
        // most likely to have been sitting untouched.
        expect(Succession::whatWouldMove(whoUsesTheCasesScreen('rakesh')))
            ->toMatchArray(['approvals_waiting' => 4, 'direct_reports' => 2]);

        $page = Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshsExit->getKey()])
            ->assertOk()
            ->assertActionVisible('handOverTheirWork')
            ->mountAction('handOverTheirWork')
            ->assertActionMounted('handOverTheirWork');

        // The words above the confirm button, read off the control once it is open rather
        // than out of the rendered HTML: a modal's own content is drawn in the browser and
        // is not in the page's first response, and this sentence is the whole safeguard.
        // Read this way rather than by asking the page, because a method a page offers in
        // public is one the browser may call by name — which would hand the roles somebody
        // holds to anybody able to open their exit.
        expect($page->instance()->getMountedAction()->getModalDescription())
            ->toContain('4 approvals waiting on Rakesh Menon')
            ->toContain('2 people reporting to them')
            ->toContain('HR head')
            ->toContain('no undo');
    });
});

it('hands the work on, and says so on the exit and on every case whose step moved', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $rakesh = whoUsesTheCasesScreen('rakesh');
        $priya = whoUsesTheCasesScreen('priya');
        $rakeshsExit = theSeededExitOf('rakesh');

        // A step of his that somebody has actually opened, so there is a row to move and a
        // case for the move to be written into. Rakesh picks up one of the two requests
        // waiting on him and does not answer it.
        $request = ProcessCase::query()
            ->whereRelation('template', 'key', 'hiring_request')
            ->whereNull('closed_at')
            ->firstOrFail();

        (new CaseEngine)->claim($request, 2, $rakesh);

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshsExit->getKey()])
            ->callAction('handOverTheirWork', [
                'successor' => $priya->getKey(),
                'effective_at' => CarbonImmutable::now()->toDateString(),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        // The exit itself says what moved, because it is where somebody reading Rakesh's
        // departure next year would look first.
        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshsExit->getKey()])
            ->assertOk()
            ->assertSee('Priya Nair')
            // Above the step-by-step list rather than under it: a case can have forty steps
            // and nobody scrolls past them to find out that the work changed hands.
            ->assertSeeInOrder(['The handover settled here', 'Step by step'])
            // And the control is gone, because a second attempt can only be refused.
            ->assertActionHidden('handOverTheirWork');

        // And the request whose approval changed hands says so on its own history, rather
        // than quietly starting to read Priya's name.
        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $request->getKey()])
            ->assertOk()
            ->assertSee('Moved from Rakesh Menon to Priya Nair');

        expect(implode(' ', collect((new CaseHistory)->stepByStep($request->fresh()))
            ->firstWhere('sequence', 2)['earlier']))
            ->toContain('Moved from Rakesh Menon to Priya Nair')
            ->toContain('left the company');
    });
});

it('says in a sentence why a handover was refused, rather than showing an error page', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $rakeshsExit = theSeededExitOf('rakesh');

        // Nobody confirms that the work passes to themselves. The plan has the leaver's
        // manager nominating and somebody else confirming, and one person taking a branch's
        // roles, approvals and reporting lines on their own say-so is the signature this
        // product exists to make impossible.
        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshsExit->getKey()])
            ->callAction('handOverTheirWork', [
                'successor' => $chandni->getKey(),
                'effective_at' => CarbonImmutable::now()->toDateString(),
            ])
            ->assertActionHalted('handOverTheirWork')
            ->assertNotified('This work cannot be handed over');

        expect(Succession::query()->count())->toBe(0);
    });
});

it('sends whoever is correcting a wrong successor to the person who holds the work now', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $rakeshsExit = theSeededExitOf('rakesh');

        Succession::handOver(
            $rakeshsExit,
            whoUsesTheCasesScreen('priya'),
            $chandni,
            CarbonImmutable::now()->toDateString(),
        );

        // The control is not offered a second time, and the record refuses it anyway with
        // a sentence naming who to hand it on from instead.
        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $rakeshsExit->getKey()])
            ->assertOk()
            ->assertActionHidden('handOverTheirWork');

        expect(fn () => Succession::handOver($rakeshsExit, whoUsesTheCasesScreen('deepak'), $chandni))
            ->toThrow(ProcessRefused::class, 'hand it on from Priya Nair');
    });
});

it('keeps the handover off a case about nobody, and off somebody who covers one branch', function () {
    TenantContext::run($this->meridian, function () {
        $chandni = whoUsesTheCasesScreen('chandni');
        $rakesh = whoUsesTheCasesScreen('rakesh');

        // A hiring request is about a vacancy, so there is nobody whose work could move.
        $request = ProcessCase::query()->whereRelation('template', 'key', 'hiring_request')->firstOrFail();

        Livewire::actingAs($chandni)->test(ViewCase::class, ['record' => $request->getKey()])
            ->assertOk()
            ->assertActionHidden('handOverTheirWork');

        // And a client who ticks settling a handover onto their own HR head role, which
        // Rakesh holds over the Shimla branch alone. A handover moves grants that can cover
        // the whole company, so holding the action in a corner of the company is not enough
        // — this is the same conclusion the roles screen reached about changing what a role
        // can do.
        Role::query()->where('key', 'hr_head')->sole()
            ->permissions()->create(['permission' => Permission::SettleHandover]);

        app(PermissionResolver::class)->forget();

        expect(app(PermissionResolver::class)
            ->allows($rakesh, Permission::SettleHandover))->toBeTrue();

        Livewire::actingAs($rakesh)->test(ViewCase::class, ['record' => theSeededExitOf('anjali')->getKey()])
            ->assertOk()
            ->assertActionHidden('handOverTheirWork');
    });
});
