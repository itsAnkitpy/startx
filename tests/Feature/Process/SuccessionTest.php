<?php

use App\Authorization\AdministratorFloor;
use App\Exceptions\ProcessRefused;
use App\Models\CaseEvent;
use App\Models\CaseStep;
use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Succession;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;

/*
| Step 4 of module 03: somebody leaving for good.
|
| Rakesh runs Meridian's Shimla branch. Deepak and Anjali report to him, he holds the HR
| head role over that branch, and he has picked up the clearance on Anjali's exit. He
| resigns, and Priya takes over.
|
| Before anybody confirms, the exit shows what Priya is about to inherit. Confirming moves
| three things in one go and nothing is overwritten: the approvals Rakesh had opened, the
| roles he held with the scope he held them at, and the people who reported to him — each
| of whom gets a fresh dated line, with the old one left readable.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/**
 * Meridian's three levels and the two branches at the bottom of them.
 *
 * @return array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}
 */
function meridianBranches(): array
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
function someoneWorkingIn(OrgUnit $unit, string $called, ?User $manager = null): User
{
    $person = User::factory()->named($called)->create();

    $row = EmploymentRecord::factory()->forPerson($person)->in($unit);

    $manager === null ? $row->create() : $row->reportingTo($manager)->create();

    return $person;
}

/** Give somebody a role over one part of the structure, or over the whole client company. */
function roleHeldOver(User $person, string $roleKey, string $roleName, ?OrgUnit $unit = null): void
{
    $role = Role::query()->where('key', $roleKey)->first()
        ?? Role::factory()->keyed($roleKey, $roleName)->create();

    $role->assignments()->create([
        'user_id' => $person->getKey(),
        'org_unit_id' => $unit?->getKey(),
        'includes_descendants' => false,
    ]);
}

/**
 * Rakesh's departure, with everything that has to move already in place.
 *
 * @return array{
 *     chandni: User, rakesh: User, priya: User, deepak: User, anjali: User,
 *     shimla: OrgUnit, pune: OrgUnit,
 *     exitProcess: ProcessTemplate, rakeshsExit: ProcessCase, anjalisExit: ProcessCase,
 * }
 */
function rakeshsDeparture(): array
{
    $units = meridianBranches();

    $chandni = someoneWorkingIn($units['north'], 'Chandni Verma');
    $rakesh = someoneWorkingIn($units['shimla'], 'Rakesh Menon', $chandni);
    $priya = someoneWorkingIn($units['shimla'], 'Priya Nair', $chandni);
    $deepak = someoneWorkingIn($units['shimla'], 'Deepak Iyer', $rakesh);
    $anjali = someoneWorkingIn($units['shimla'], 'Anjali Rao', $rakesh);

    roleHeldOver($rakesh, 'hr_head', 'HR head', $units['shimla']);

    $exitProcess = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

    ProcessStep::factory()->of($exitProcess)->at(1, 1)->named('HR clearance')
        ->heldByTheRole('hr_head')->offering('approved')->create();

    $exitProcess->publish();

    return [
        'chandni' => $chandni,
        'rakesh' => $rakesh,
        'priya' => $priya,
        'deepak' => $deepak,
        'anjali' => $anjali,
        'shimla' => $units['shimla'],
        'pune' => $units['pune'],
        'exitProcess' => $exitProcess,
        'rakeshsExit' => (new CaseEngine)->open($exitProcess, $rakesh, $chandni),
        'anjalisExit' => (new CaseEngine)->open($exitProcess, $anjali, $rakesh),
    ];
}

/** Whoever somebody reports to on a given day, read off their dated job rows. */
function theirManagerOn(User $person, string $date): ?string
{
    return EmploymentRecord::query()
        ->where('user_id', $person->getKey())
        ->asOf($date)
        ->sole()
        ->reportsTo?->name;
}

/*
| What Priya is about to inherit
*/

it('counts approvals waiting on the leaver that nobody has opened yet', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        // Nobody has touched Anjali's clearance, so there is no row anywhere to count it
        // from — and it is exactly the work most likely to have been sitting untouched.
        expect(CaseStep::query()->count())->toBe(0);

        expect(Succession::whatWouldMove($world['rakesh'])['approvals_waiting'])->toBe(1);
    });
});

it('shows how many people report to the leaver today, and which roles they hold', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        // Somebody who used to report to Rakesh and has since moved on does not count:
        // the figure is who reports to him now, not everybody who ever did.
        EmploymentRecord::factory()->forPerson(someoneWorkingIn($world['shimla'], 'Rohit Menon'))
            ->reportingTo($world['rakesh'])->effective('2023-04-01', '2024-03-31')->create();

        expect(Succession::whatWouldMove($world['rakesh']))
            ->toMatchArray([
                'approvals_waiting' => 1,
                'direct_reports' => 2,
                'roles' => ['HR head — Shimla branch'],
            ]);
    });
});

/*
| Confirming it
*/

it('moves the approvals, the roles and the reporting lines in one go', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();
        [$rakesh, $priya, $chandni] = [$world['rakesh'], $world['priya'], $world['chandni']];

        (new CaseEngine)->claim($world['anjalisExit'], 1, $rakesh);

        Succession::handOver($world['rakeshsExit'], $priya, $chandni, '2026-09-20');

        expect(CaseStep::query()->sole()->assignee_id)->toBe($priya->getKey())
            ->and(RoleAssignment::query()->sole()->user_id)->toBe($priya->getKey())
            ->and(theirManagerOn($world['deepak'], '2026-09-20'))->toBe('Priya Nair')
            ->and(theirManagerOn($world['anjali'], '2026-09-20'))->toBe('Priya Nair');
    });
});

it('leaves the reporting line as it stood before the handover still readable', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        // Nothing was overwritten. The day before the handover still reads as Rakesh's
        // branch, which is what a case reviewed a year later is read against.
        expect(theirManagerOn($world['deepak'], '2026-09-19'))->toBe('Rakesh Menon')
            ->and(theirManagerOn($world['deepak'], '2024-06-01'))->toBe('Rakesh Menon');

        $rows = EmploymentRecord::query()
            ->where('user_id', $world['deepak']->getKey())
            ->orderBy('effective_from')
            ->get();

        expect($rows)->toHaveCount(2)
            ->and($rows->first()->effective_to->toDateString())->toBe('2026-09-19')
            ->and($rows->last()->effective_to)->toBeNull()
            ->and($rows->last()->change_reason)->toBe('Rakesh Menon left the company');
    });
});

it('links each moved reporting line to the exit that caused it, from either end', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();
        $exit = $world['rakeshsExit'];

        Succession::handOver($exit, $world['priya'], $world['chandni'], '2026-09-20');

        expect($exit->reportingLinesMoved()->pluck('user_id')->all())
            ->toEqualCanonicalizing([$world['deepak']->getKey(), $world['anjali']->getKey()]);

        $moved = EmploymentRecord::query()
            ->where('user_id', $world['deepak']->getKey())
            ->whereNull('effective_to')
            ->sole();

        expect($moved->causedByCase->getKey())->toBe($exit->getKey());
    });
});

it('keeps a transferred approval naming the person it belonged to', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        (new CaseEngine)->claim($world['anjalisExit'], 1, $world['rakesh']);

        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        // Anjali's own exit says the clearance was Rakesh's and changed hands because he
        // left — not that it was Priya's all along. It is written on her case, which is
        // where anybody reading her exit would look.
        $moved = CaseEvent::query()
            ->where('case_id', $world['anjalisExit']->getKey())
            ->where('type', Succession::StepTransferredEvent)
            ->sole();

        expect($moved->payload['from']['name'])->toBe('Rakesh Menon')
            ->and($moved->payload['to']['name'])->toBe('Priya Nair')
            ->and($moved->payload['because'])->toBe('Rakesh Menon left the company');
    });
});

it('moves a role with the part of the company it covered, and never widens it', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        $grant = RoleAssignment::query()->sole();

        expect($grant->org_unit_id)->toBe($world['shimla']->getKey())
            ->and($grant->includes_descendants)->toBeFalse();

        // "HR head, Shimla branch" arriving as "HR head" would quietly make Priya the
        // answer everywhere. Pune's exit still finds nobody.
        $rohit = someoneWorkingIn($world['pune'], 'Rohit Menon');
        $inPune = (new CaseEngine)->open($world['exitProcess'], $rohit, $world['chandni']);
        $step = $world['exitProcess']->steps->sole();

        expect((new AssigneeResolver)->resolve($inPune, $step))->toBeEmpty();
    });
});

it('lets a departing administrator hand their own access on', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        roleHeldOver($world['rakesh'], Role::AdministratorKey, 'Administrator');
        roleHeldOver($world['chandni'], Role::AdministratorKey, 'Administrator');

        expect(AdministratorFloor::count())->toBe(2);

        // Two administrators before and two after — the same count with one name swapped.
        // Refusing this would block the one exit that most needs authority moved on.
        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        expect(AdministratorFloor::count())->toBe(2)
            ->and(RoleAssignment::query()
                ->where('user_id', $world['priya']->getKey())
                ->count())->toBe(2);
    });
});

/*
| When it cannot go through
*/

it('rolls back the approvals and the roles when the reporting lines cannot move', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();
        $rakesh = $world['rakesh'];

        (new CaseEngine)->claim($world['anjalisExit'], 1, $rakesh);

        // Dated before Deepak's current job row even began, so there is no room in front
        // of it for the day that row would have to end on.
        expect(fn () => Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2024-01-01'))
            ->toThrow(ProcessRefused::class, 'Date the handover after that day');

        // All three or none. A handover that moved the approvals and then failed on the
        // reporting lines would leave work sitting on somebody who has gone.
        expect(CaseStep::query()->sole()->assignee_id)->toBe($rakesh->getKey())
            ->and(RoleAssignment::query()->sole()->user_id)->toBe($rakesh->getKey())
            ->and(theirManagerOn($world['deepak'], '2026-09-20'))->toBe('Rakesh Menon')
            ->and(Succession::query()->count())->toBe(0);
    });
});

it('refuses a successor who is the leaver, or who cannot sign in', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();
        $exit = $world['rakeshsExit'];

        expect(fn () => Succession::handOver($exit, $world['rakesh'], $world['chandni']))
            ->toThrow(ProcessRefused::class, 'cannot take over from themselves');

        $world['priya']->update(['active' => false]);

        // Nothing resolves to a dead account, so the branch would be inherited into a
        // hole and land on the client's stand-in with nobody having asked for that.
        expect(fn () => Succession::handOver($exit, $world['priya'], $world['chandni']))
            ->toThrow(ProcessRefused::class, 'their account cannot sign in');
    });
});

it('refuses a handover on a case that is not about anybody', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        $aboutNobody = ProcessCase::factory()->create(['subject_user_id' => null]);

        expect(fn () => Succession::handOver($aboutNobody, $world['priya'], $world['chandni']))
            ->toThrow(ProcessRefused::class, 'only be settled inside a case about the person leaving');
    });
});

it('puts the successor in the leaver\'s place when they were one of his own team', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        // Promoting somebody from the branch to run it is the ordinary way a branch is
        // handed on, and nobody can be made to report to themselves.
        Succession::handOver($world['rakeshsExit'], $world['deepak'], $world['chandni'], '2026-09-20');

        expect(theirManagerOn($world['anjali'], '2026-09-20'))->toBe('Deepak Iyer')
            ->and(theirManagerOn($world['deepak'], '2026-09-20'))->toBe('Chandni Verma')
            ->and(theirManagerOn($world['deepak'], '2026-09-19'))->toBe('Rakesh Menon');
    });
});

it('retires a role the successor already shares rather than colliding with it', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        // Rakesh and Priya share the Shimla HR head queue, and his grant reaches down
        // into everything below the branch while hers does not.
        roleHeldOver($world['priya'], 'hr_head', 'HR head', $world['shimla']);
        RoleAssignment::query()->where('user_id', $world['rakesh']->getKey())
            ->update(['includes_descendants' => true]);

        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        $grant = RoleAssignment::query()->sole();

        // One grant left, still over Shimla, and it kept the wider of the two reaches —
        // otherwise the work below the branch would quietly stop being anybody's.
        expect($grant->user_id)->toBe($world['priya']->getKey())
            ->and($grant->org_unit_id)->toBe($world['shimla']->getKey())
            ->and($grant->includes_descendants)->toBeTrue();
    });
});

it('refuses a second handover and names whoever holds the work now', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        // A second one finds nothing left to move and would look to whoever pressed
        // confirm exactly like one that worked.
        expect(fn () => Succession::handOver($world['rakeshsExit'], $world['deepak'], $world['chandni'], '2026-09-21'))
            ->toThrow(ProcessRefused::class, 'work was handed to Priya Nair on 2026-09-20');
    });
});

it('writes the handover onto the exit\'s own history', function () {
    TenantContext::run($this->meridian, function () {
        $world = rakeshsDeparture();

        (new CaseEngine)->claim($world['anjalisExit'], 1, $world['rakesh']);

        Succession::handOver($world['rakeshsExit'], $world['priya'], $world['chandni'], '2026-09-20');

        // Somebody reading Rakesh's exit sees the handover on the exit itself, not only
        // scattered across the cases and job rows it moved.
        $settled = CaseEvent::query()
            ->where('case_id', $world['rakeshsExit']->getKey())
            ->where('type', Succession::HandoverSettledEvent)
            ->sole();

        expect($settled->payload['to']['name'])->toBe('Priya Nair')
            ->and($settled->payload['effective_at'])->toBe('2026-09-20')
            ->and($settled->payload['moved'])
            ->toEqual(['approvals' => 1, 'roles' => 1, 'reporting_lines' => 2]);
    });
});
