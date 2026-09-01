<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\EmploymentRecord;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Database\Factories\ProcessStepFactory;
use Illuminate\Database\Eloquent\Collection;

/*
| Step 1 of module 03: whose job a step is.
|
| Six ways a step finds its people, the climb up the structure when the role is not held
| in the person's own branch, and the answer when nobody can be found at all — one level
| up, then the client's stand-in, then nobody, with a line on the case's record either
| way.
|
| Two rules run underneath all of it. Nobody is ever written down: the answer is worked
| out every time somebody asks, so a leaver stops appearing with no repair having run.
| And nobody is ever substituted silently: a step nobody holds stays open and warned,
| because a clearance that reads as given because nobody could be found to give it is a
| settlement paid against a clearance no person performed.
|
| Meridian's structure through the whole file: the company, the North business line
| inside it, and the Shimla and Pune branches inside that.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/**
 * Meridian's three levels, and the two branches at the bottom of them.
 *
 * @return array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}
 */
function meridiansStructure(): array
{
    $company = OrgUnit::factory()->ofType('company')->create(['name' => 'Meridian Logistics']);
    $north = OrgUnit::factory()->under($company, 'business_line')->create(['name' => 'North Logistics']);

    return [
        'company' => $company,
        'north' => $north,
        'shimla' => OrgUnit::factory()->under($north, 'branch')->create(['name' => 'Shimla branch']),
        'pune' => OrgUnit::factory()->under($north, 'branch')->create(['name' => 'Pune branch']),
    ];
}

/** Somebody with a job in one part of the structure, and a manager where they have one. */
function personIn(OrgUnit $unit, string $called, ?User $manager = null): User
{
    $person = User::factory()->named($called)->create();

    $row = EmploymentRecord::factory()->forPerson($person)->in($unit);

    if ($manager !== null) {
        $row = $row->reportingTo($manager);
    }

    $row->create();

    return $person;
}

/** Somebody who has left: their account is off and their job row has an end date. */
function whoHasLeft(User $person): User
{
    EmploymentRecord::query()->where('user_id', $person->getKey())
        ->update(['effective_to' => '2026-07-31', 'last_working_day' => '2026-07-31']);

    $person->update(['active' => false]);

    return $person;
}

/** Grant a role, over one part of the structure or over the whole client company. */
function grant(User $person, string $roleKey, ?OrgUnit $over = null, bool $reachingDown = false): void
{
    $role = Role::query()->where('key', $roleKey)->first()
        ?? Role::factory()->keyed($roleKey)->create();

    $role->assignments()->create([
        'user_id' => $person->getKey(),
        'org_unit_id' => $over?->getKey(),
        'includes_descendants' => $reachingDown,
    ]);
}

it('never answers one client company with another one remembered answer', function () {
    $vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex-remembered']);

    // The same role name at two client companies, held by a different person at each. A
    // role's name means nothing to any check, which is exactly why the same name turning up
    // twice is ordinary rather than a coincidence.
    $meridiansOwn = TenantContext::run($this->meridian, function () {
        $rakesh = personIn(meridiansStructure()['shimla'], 'Rakesh Menon');
        grant($rakesh, 'hr_head');

        return [$rakesh, anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRoleAnywhere('hr_head'))];
    });

    $vertexsOwn = TenantContext::run($vertex, function () {
        $meera = personIn(OrgUnit::create(['type' => 'company', 'name' => 'Vertex Foods']), 'Meera Joshi');
        grant($meera, 'hr_head');

        return [$meera, anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRoleAnywhere('hr_head'))];
    });

    // One resolver asked about both. Nothing does this today — every screen makes its own —
    // but module 06's scheduled pass walks the client companies inside one process, and a
    // remembered answer is handed back without going near the database, so the wall that
    // separates the two companies would never be consulted.
    $resolver = new AssigneeResolver;

    $atMeridian = TenantContext::run($this->meridian, fn () => $resolver->whoCanRaiseIt(
        $meridiansOwn[1]->steps()->first(), 'exit'
    ));

    $atVertex = TenantContext::run($vertex, fn () => $resolver->whoCanRaiseIt(
        $vertexsOwn[1]->steps()->first(), 'exit'
    ));

    expect($atMeridian->pluck('id')->all())->toBe([$meridiansOwn[0]->getKey()])
        // Vertex's own person, not Meridian's, and not nobody either — asked second, it is
        // the one that would come back wrong.
        ->and($atVertex->pluck('id')->all())->toBe([$vertexsOwn[0]->getKey()]);
});

/**
 * A live exit whose only step is the one being tested.
 *
 * @param  callable(ProcessStepFactory): ProcessStepFactory  $whoItBelongsTo
 */
function anExitWhoseOneStepIs(callable $whoItBelongsTo): ProcessTemplate
{
    $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

    $whoItBelongsTo(ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance'))->create();

    $exit->publish();

    return $exit;
}

/** The names of the people whose job that case's one step is, in the order resolved. */
function whoHoldsIt(ProcessCase $case): array
{
    $step = $case->template->steps->sole();

    return (new AssigneeResolver)->resolve($case->fresh(), $step)
        ->map(fn (User $person) => $person->name)
        ->all();
}

/** Every line on the case saying a step has nobody holding it. */
function warningsOn(ProcessCase $case): Collection
{
    return CaseEvent::query()
        ->where('case_id', $case->getKey())
        ->where('type', AssigneeResolver::NobodyHoldsItEvent)
        ->get();
}

/*
| The reporting line
*/

it('gives a step to the manager of the person the case is about', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $chandni = personIn($units['north'], 'Chandni Iyer');
        $deepak = personIn($units['shimla'], 'Deepak Rao', $chandni);
        $rakesh = personIn($units['shimla'], 'Rakesh Menon', $deepak);
        $anjali = personIn($units['shimla'], 'Anjali Nair', $deepak);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step);
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe(['Deepak Rao']);
    });
});

it('climbs to the manager above when the manager has left the company', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $chandni = personIn($units['north'], 'Chandni Iyer');
        $deepak = whoHasLeft(personIn($units['shimla'], 'Deepak Rao', $chandni));
        $rakesh = personIn($units['shimla'], 'Rakesh Menon', $deepak);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step);
        $case = (new CaseEngine)->open($exit, $rakesh, $chandni);

        // Deepak's own job row has an end date on it now, and the climb still has to read
        // through him to reach Chandni — a walk that only looked at rows true today would
        // stop dead at him and send Rakesh's exit to the client's stand-in instead.
        expect(whoHoldsIt($case))->toBe(['Chandni Iyer']);
    });
});

it('gives a step to the manager of whoever opened the case, where the step says so', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $chandni = personIn($units['north'], 'Chandni Iyer');
        $deepak = personIn($units['shimla'], 'Deepak Rao', $chandni);
        $rakesh = personIn($units['shimla'], 'Rakesh Menon', $deepak);
        $priya = personIn($units['pune'], 'Priya Sharma', $chandni);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheInitiatorsManager());
        $case = (new CaseEngine)->open($exit, $rakesh, $priya);

        // Priya opened it, so it is her manager and not Rakesh's.
        expect(whoHoldsIt($case))->toBe(['Chandni Iyer']);
    });
});

/*
| A role, and the climb up the structure
*/

it('gives a role step to the holder in the person’s own branch rather than the one above', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $priya = personIn($units['north'], 'Priya Sharma');

        grant($anjali, 'hr_head', $units['shimla']);
        grant($priya, 'hr_head', $units['north']);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe(['Anjali Nair']);
    });
});

it('climbs the structure to the business line when the branch holds the role nobody', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $priya = personIn($units['north'], 'Priya Sharma');

        grant($priya, 'hr_head', $units['north']);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $priya);

        expect(whoHoldsIt($case))->toBe(['Priya Sharma']);
    });
});

it('lets a role held over a business line and everything under it answer for the branch', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $priya = personIn($units['north'], 'Priya Sharma');

        grant($priya, 'hr_head', $units['north'], reachingDown: true);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $priya);

        expect(whoHoldsIt($case))->toBe(['Priya Sharma']);
    });
});

it('lets a role held over the whole client company answer for anybody in it', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['pune'], 'Anjali Nair');

        grant($anjali, 'hr_head');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe(['Anjali Nair']);
    });
});

it('gives a step to a company-wide role holder alongside the branch’s own', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $priya = personIn($units['north'], 'Priya Sharma');

        // Anjali holds it over Shimla; Priya holds it over the whole company, which is
        // what a grant naming no part of the structure means. Both answer, and the first
        // to act claims — the same as a grant held above the branch that says it reaches
        // down. A grant held above the branch that does *not* say so waits its turn,
        // which is the test above this one.
        grant($anjali, 'hr_head', $units['shimla']);
        grant($priya, 'hr_head');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toHaveCount(2)
            ->toContain('Anjali Nair', 'Priya Sharma');
    });
});

it('never lets one branch’s role holder answer for another branch', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['pune'], 'Anjali Nair');
        $rohit = personIn($units['north'], 'Rohit Bansal');

        // Held over Pune only, and Shimla is beside Pune rather than under it. The climb
        // goes up and a grant reaches down only when it says so, so this must not answer.
        grant($anjali, 'hr_head', $units['pune']);

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $rohit->getKey());

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $rohit);

        expect(whoHoldsIt($case))->toBe(['Rohit Bansal']);
    });
});

it('gives a whole team the same step when the role is held by several people', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $deepak = personIn($units['pune'], 'Deepak Rao');

        grant($anjali, 'it_team');
        grant($deepak, 'it_team');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRoleAnywhere('it_team'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toHaveCount(2)
            ->toContain('Anjali Nair', 'Deepak Rao');
    });
});

it('treats a role holder who has left as though the role were vacant, with nothing repaired', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $priya = personIn($units['north'], 'Priya Sharma');

        grant($anjali, 'hr_head', $units['shimla']);
        grant($priya, 'hr_head', $units['north']);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $priya);

        expect(whoHoldsIt($case))->toBe(['Anjali Nair']);

        whoHasLeft($anjali);

        // Her grant is untouched and no repair has run. She simply stops appearing, and
        // the step climbs to the business line the moment the next person reads it.
        expect(whoHoldsIt($case))->toBe(['Priya Sharma'])
            ->and(RoleAssignment::query()->where('user_id', $anjali->getKey())->count())->toBe(1);
    });
});

/*
| One named person
*/

it('gives a step to the one person a template names, by their work address', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldBy($anjali->work_email));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe(['Anjali Nair']);
    });
});

/*
| Never your own approver
*/

it('never lets the person a case is about approve their own step, and climbs instead', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $chandni = personIn($units['north'], 'Chandni Iyer');
        $rakesh = personIn($units['shimla'], 'Rakesh Menon', $chandni);
        $priya = personIn($units['north'], 'Priya Sharma');

        // Rakesh is the branch HR head and Rakesh is the one leaving.
        grant($rakesh, 'hr_head', $units['shimla']);
        grant($priya, 'hr_head', $units['north']);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $priya);

        expect(whoHoldsIt($case))->toBe(['Priya Sharma']);
    });
});

it('climbs from a named person to their manager when the named person is the one leaving', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $chandni = personIn($units['north'], 'Chandni Iyer');
        $rakesh = personIn($units['shimla'], 'Rakesh Menon', $chandni);

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldBy($rakesh->work_email));
        $case = (new CaseEngine)->open($exit, $rakesh, $chandni);

        expect(whoHoldsIt($case))->toBe(['Chandni Iyer']);
    });
});

/*
| A case with no department of its own
*/

/**
 * A request about a vacancy: the first step asks which part of the company it is for, and
 * the second belongs to whoever holds a role over whatever that answer named.
 */
function aRequestScopedByItsOwnAnswer(User $raisedBy, string $roleKey = 'lob_head'): ProcessTemplate
{
    $form = FormDefinition::factory()->named('request', 'Request')->create();

    FormField::factory()->on($form)->at(1)->required()
        ->asking('department', 'Which part of the company', FormField::OrgUnitPicker)->create();

    $form->publish();

    $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

    ProcessStep::factory()->of($hiring)->at(1, 1)->named('Raise request')
        ->asking($form)->heldBy($raisedBy->work_email)->offering('approved')->create();

    ProcessStep::factory()->of($hiring)->at(2, 2)->named('Approval')
        ->heldByTheRole($roleKey, 'department')->offering('approved', 'rejected')->create();

    $hiring->publish();

    return $hiring;
}

/** A request raised naming one department, so its approval step is the one waiting. */
function aRequestNaming(OrgUnit $department, User $raisedBy): ProcessCase
{
    $engine = new CaseEngine;
    $request = $engine->open(aRequestScopedByItsOwnAnswer($raisedBy), by: $raisedBy);

    $engine->decide($request, 1, 'approved', $raisedBy, ['department' => $department->getKey()]);

    return $request->fresh();
}

/** The names of the people the approval step of a request belongs to. */
function whoApprovesIt(ProcessCase $request): array
{
    $approval = $request->template->steps->firstWhere('sequence', 2);

    return (new AssigneeResolver)->resolve($request, $approval)
        ->map(fn (User $person) => $person->name)
        ->all();
}

it('sends an approval to the head of the department the request itself named', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $priya = personIn($units['pune'], 'Priya Sharma');

        grant($rakesh, 'lob_head', $units['shimla']);
        grant($priya, 'lob_head', $units['pune']);

        // A vacancy in Shimla goes to Shimla's head, and Pune's never sees it — the same
        // rule an exit gets from the leaver's own job row, on a case that has no job row.
        expect(whoApprovesIt(aRequestNaming($units['shimla'], $anjali)))->toBe(['Rakesh Menon']);
    });
});

it('climbs to the unit above when the department a request named has nobody in the job', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $chandni = personIn($units['north'], 'Chandni Verma');

        grant($chandni, 'lob_head', $units['north']);

        expect(whoApprovesIt(aRequestNaming($units['shimla'], $anjali)))->toBe(['Chandni Verma']);
    });
});

it('leaves a request approved by nobody, and says so, when no unit above it holds the role either', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $anjali = personIn($units['shimla'], 'Anjali Nair');

        $request = aRequestNaming($units['shimla'], $anjali);

        // Nobody at all, and the case says so on its own record rather than the request
        // quietly widening to whoever holds the role anywhere in the company.
        expect(whoApprovesIt($request))->toBe([]);

        expect(warningsOn($request)->sole()->payload['step'])->toBe('Approval');
    });
});

it('sends the approval somewhere else once the department on the request is corrected', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $priya = personIn($units['pune'], 'Priya Sharma');

        grant($rakesh, 'lob_head', $units['shimla']);
        grant($priya, 'lob_head', $units['pune']);

        $request = aRequestNaming($units['shimla'], $anjali);

        // The request is corrected to name Pune instead. Whose job a step is has always
        // been worked out fresh rather than written down, and the vacancy really did move,
        // so the approval moves with it.
        $request->liveSteps()->where('sequence', 1)->sole()
            ->update(['payload' => ['department' => $units['pune']->getKey()]]);

        expect(whoApprovesIt($request->fresh()))->toBe(['Priya Sharma']);
    });
});

it('still reads the frozen job row, not an answer, on a case that is about a person', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');
        $priya = personIn($units['pune'], 'Priya Sharma');

        grant($anjali, 'hr_head', $units['shimla']);
        grant($priya, 'hr_head', $units['pune']);

        // An exit whose first step asks a department and whose clearance names it. Rakesh's
        // own job row says Shimla and the answer says Pune, and the row wins: a clearance
        // opened against a branch must not move into another branch's queue because
        // somebody typed a department into a later box.
        $form = FormDefinition::factory()->named('opening', 'Opening note')->create();

        FormField::factory()->on($form)->at(1)
            ->asking('department', 'Which part of the company', FormField::OrgUnitPicker)->create();

        $form->publish();

        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Opening note')
            ->asking($form)->heldBy($anjali->work_email)->offering('approved')->create();

        ProcessStep::factory()->of($exit)->at(2, 2)->named('HR clearance')
            ->heldByTheRole('hr_head', 'department')->offering('approved', 'rejected')->create();

        $exit->publish();

        $engine = new CaseEngine;
        $case = $engine->open($exit, $rakesh, $anjali);

        $engine->decide($case, 1, 'approved', $anjali, ['department' => $units['pune']->getKey()]);

        $clearance = $case->template->steps->firstWhere('sequence', 2);

        expect((new AssigneeResolver)->resolve($case->fresh(), $clearance)->pluck('name')->all())
            ->toBe(['Anjali Nair']);
    });
});

/*
| When nobody can be found
*/

it('hands a step nobody holds to the client’s stand-in, and says so on the case', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $rohit = personIn($units['north'], 'Rohit Bansal');

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $rohit->getKey());

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $rohit);

        expect(whoHoldsIt($case))->toBe(['Rohit Bansal']);

        $warning = warningsOn($case)->sole();

        expect($warning->payload['step'])->toBe('HR clearance')
            ->and($warning->payload['sequence'])->toBe(1)
            ->and($warning->payload['held_by_the_stand_in'])->toBeTrue()
            ->and($warning->payload['stand_in_id'])->toBe((int) $rohit->getKey());
    });
});

it('leaves a step held by nobody when the client has named no stand-in', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe([]);

        expect(warningsOn($case)->sole()->payload['held_by_the_stand_in'])->toBeFalse();
    });
});

it('leaves a step held by nobody when the named stand-in has left, or was never there', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $rohit = whoHasLeft(personIn($units['north'], 'Rohit Bansal'));

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $rohit->getKey());

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $rohit);

        expect(whoHoldsIt($case))->toBe([]);

        // The same answer for an id pointing at no account at all, which is what a
        // deleted account and a mistyped number both look like from here.
        app(Settings::class)->set(AssigneeResolver::StandInSetting, 987654);

        expect(whoHoldsIt($case))->toBe([]);
    });
});

it('never lets another client company’s account be the stand-in', function () {
    $vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);

    $outsider = TenantContext::run($vertex, fn () => User::factory()->named('Someone Else')->create());

    TenantContext::run($this->meridian, function () use ($outsider) {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $outsider->getKey());

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe([]);
    });
});

it('says a step has nobody once, however many times its queue is read', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        foreach (range(1, 5) as $ignored) {
            whoHoldsIt($case);
        }

        expect(warningsOn($case))->toHaveCount(1);
    });
});

it('leaves a step nobody holds open and waiting, never approved and never skipped', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $anjali = personIn($units['shimla'], 'Anjali Nair');

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->heldByTheRole('hr_head'));
        $case = (new CaseEngine)->open($exit, $rakesh, $anjali);

        expect(whoHoldsIt($case))->toBe([]);

        $case = $case->fresh();

        expect($case->state)->toBe(ProcessCase::Open)
            ->and((new AvailableSteps)->for($case)->sole()->step->name)->toBe('HR clearance')
            ->and($case->steps()->count())->toBe(0);
    });
});

/*
| Somebody with no account
*/

it('resolves nobody for a step whose actor has no account, and does not call that a vacancy', function () {
    TenantContext::run($this->meridian, function () {
        $units = meridiansStructure();
        $rakesh = personIn($units['shimla'], 'Rakesh Menon');
        $rohit = personIn($units['north'], 'Rohit Bansal');

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $rohit->getKey());

        $exit = anExitWhoseOneStepIs(fn ($step) => $step->external());
        $case = (new CaseEngine)->open($exit, $rakesh, $rohit);

        // Nobody, and deliberately not the stand-in: the candidate's own form handed to a
        // client's HR manager would record their answers against an employee who never
        // typed them. Permission to act on it is the signed link and nothing else.
        expect(whoHoldsIt($case))->toBe([])
            ->and(warningsOn($case))->toHaveCount(0);
    });
});

/*
| What publishing refuses
*/

it('refuses to publish a step that disagrees with itself about whether its actor has an account', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Handover note')
            ->state(['participant_kind' => 'external'])->create();

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'belongs to [external]');
    });
});

it('refuses to publish a step that sends an employee’s approval to an outside address', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        // The other way round from the test above: nobody is looked for, and yet the step
        // says its actor is an employee. Left alone it would send an employee's approval
        // out as a link to whatever address the case happened to carry.
        ProcessStep::factory()->of($exit)->at(1, 1)->named('Handover note')
            ->state(['participant_kind' => 'internal', 'assignee_rule' => ['kind' => 'external']])->create();

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'It has to be one or the other.');
    });
});

it('refuses to publish a step that looks for its people in a way this product does not have', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance')
            ->state(['assignee_rule' => ['kind' => 'whoever_is_free']])->create();

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'not one of the ways a step can find its people');
    });
});
