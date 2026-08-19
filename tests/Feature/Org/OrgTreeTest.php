<?php

use App\Exceptions\OrgUnitCycle;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The shape of this table follows Workday: one parent per unit, no fixed number of
| levels, and the client's own word for each level. What the tests below check is that
| two genuinely different structures live in it with no migration between them, and that
| real-world untidiness — repeated names among siblings, codes on some levels and none on
| others — does not break it.
|
| Meridian Logistics is the three-level client: company, business line, sub-business
| line. Vertex Foods is five levels with its own names for them.
*/

/** Meridian's ten business lines. None of them carries a code. */
const MERIDIAN_BUSINESS_LINES = [
    'Corporate', 'Retail Fulfilment', 'Freight', 'Distribution', 'Last Mile',
    'Engineering', 'Consulting', 'Retail North', 'Retail South', 'Cold Chain',
];

/**
 * What sits under Corporate. 'Admin' appears twice on purpose — a real structure
 * repeats names, so nothing here may depend on a name being unique.
 */
const MERIDIAN_CORPORATE_UNITS = [
    'Finance', 'Legal', 'Procurement', 'People', 'Communications', 'Analytics',
    'IT Support', 'Facilities', 'Admin', 'Management', 'Admin',
];

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

/**
 * Build Meridian's structure and hand back its two companies.
 *
 * @return array{0: OrgUnit, 1: OrgUnit}
 */
function loadMeridianStructure(): array
{
    $logistics = OrgUnit::create([
        'type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.', 'code' => 'MLPL',
    ]);

    $staffing = OrgUnit::create([
        'type' => 'company', 'name' => 'Meridian Staffing Pvt. Ltd.', 'code' => 'MSPL',
    ]);

    foreach (MERIDIAN_BUSINESS_LINES as $name) {
        $line = OrgUnit::create([
            'type' => 'business_line', 'name' => $name, 'parent_id' => $logistics->getKey(),
        ]);

        if ($name !== 'Corporate') {
            continue;
        }

        foreach (MERIDIAN_CORPORATE_UNITS as $subName) {
            OrgUnit::create([
                'type' => 'sub_business_line', 'name' => $subName, 'parent_id' => $line->getKey(),
            ]);
        }
    }

    return [$logistics, $staffing];
}

it('loads a three-level structure with repeated names and codes on only one level', function () {
    TenantContext::run($this->meridian, function () {
        [$logistics, $staffing] = loadMeridianStructure();

        expect(OrgUnit::query()->roots()->pluck('code')->all())->toBe(['MLPL', 'MSPL'])
            ->and($logistics->children)->toHaveCount(10)
            ->and($staffing->children)->toHaveCount(0)
            ->and($logistics->descendants())->toHaveCount(21);

        $corporate = OrgUnit::query()->where('name', 'Corporate')->sole();

        $inNameOrder = MERIDIAN_CORPORATE_UNITS;
        sort($inNameOrder);

        expect($corporate->descendants()->pluck('name')->all())->toBe($inNameOrder)
            ->and($corporate->descendants()->where('name', 'Admin'))->toHaveCount(2)
            ->and($corporate->parent->code)->toBe('MLPL');

        $people = OrgUnit::query()->where('name', 'People')->sole();

        expect($people->ancestors()->pluck('name')->all())
            ->toBe(['Corporate', 'Meridian Logistics Pvt. Ltd.'])
            ->and($people->ancestors()->pluck('depth')->all())->toBe([1, 2])
            ->and($people->isDescendantOf($logistics))->toBeTrue()
            ->and($people->isDescendantOf($staffing))->toBeFalse();
    });
});

it('loads a five-level structure with the client\'s own names for the levels, and no migration', function () {
    TenantContext::run($this->vertex, function () {
        $names = ['Vertex Foods', 'North', 'Punjab', 'Ludhiana Plant', 'Cold Chain'];
        $types = ['company', 'region', 'state', 'plant', 'team'];

        $parent = null;

        foreach ($names as $index => $name) {
            $parent = OrgUnit::create([
                'type' => $types[$index],
                'name' => $name,
                'parent_id' => $parent?->getKey(),
            ]);
        }

        $root = OrgUnit::query()->roots()->sole();

        expect($root->name)->toBe('Vertex Foods')
            ->and($root->descendants()->pluck('type')->all())->toBe(['region', 'state', 'plant', 'team'])
            ->and($root->descendants()->pluck('depth')->all())->toBe([1, 2, 3, 4])
            ->and($parent->ancestors())->toHaveCount(4);
    });
});

it('keeps one client company\'s structure out of another\'s walk, in the recursive query itself', function () {
    [$meridianRoot, $vertexRoot] = [
        TenantContext::run($this->meridian, fn () => loadMeridianStructure()[0]),
        TenantContext::run($this->vertex, function () {
            $root = OrgUnit::create(['type' => 'company', 'name' => 'Vertex Foods']);
            OrgUnit::create(['type' => 'region', 'name' => 'North', 'parent_id' => $root->getKey()]);

            return $root;
        }),
    ];

    TenantContext::run($this->meridian, function () use ($meridianRoot) {
        expect($meridianRoot->descendants())->toHaveCount(21);
    });

    // The walk is raw SQL, so the Eloquent scope is not in play, and here the database
    // policy is deliberately stood down as well. What holds the answer together is that
    // every parent link carries the client company, so the walk cannot leave it.
    TenantContext::cross(function () use ($meridianRoot, $vertexRoot) {
        expect($meridianRoot->descendants())->toHaveCount(21)
            ->and($vertexRoot->descendants())->toHaveCount(1);
    }, reason: 'test: proving the walk cannot leave one client company');
});

it('refuses a unit placed under itself', function () {
    TenantContext::run($this->meridian, function () {
        $unit = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);

        expect(fn () => $unit->update(['parent_id' => $unit->getKey()]))
            ->toThrow(OrgUnitCycle::class, 'cannot be placed under itself');
    });
});

it('refuses a unit placed under something already below it', function () {
    TenantContext::run($this->meridian, function () {
        $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
        $line = OrgUnit::create(['type' => 'business_line', 'name' => 'Corporate', 'parent_id' => $company->getKey()]);
        $team = OrgUnit::create(['type' => 'sub_business_line', 'name' => 'People', 'parent_id' => $line->getKey()]);

        expect(fn () => $company->update(['parent_id' => $team->getKey()]))
            ->toThrow(OrgUnitCycle::class, 'which already sits below it');

        expect($company->fresh()->parent_id)->toBeNull();
    });
});

it('allows a unit to move to a different branch', function () {
    TenantContext::run($this->meridian, function () {
        $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
        $corporate = OrgUnit::create(['type' => 'business_line', 'name' => 'Corporate', 'parent_id' => $company->getKey()]);
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight', 'parent_id' => $company->getKey()]);
        $analytics = OrgUnit::create(['type' => 'sub_business_line', 'name' => 'Analytics', 'parent_id' => $corporate->getKey()]);

        $analytics->update(['parent_id' => $freight->getKey()]);

        expect($corporate->descendants())->toHaveCount(0)
            ->and($freight->descendants()->pluck('name')->all())->toBe(['Analytics'])
            ->and($analytics->fresh()->ancestors()->pluck('name')->all())
            ->toBe(['Freight', 'Meridian Logistics Pvt. Ltd.']);
    });
});

it('refuses a unit placed under another client company\'s unit', function () {
    $vertexRoot = TenantContext::run(
        $this->vertex,
        fn () => OrgUnit::create(['type' => 'company', 'name' => 'Vertex Foods'])
    );

    TenantContext::run($this->meridian, function () use ($vertexRoot) {
        // The Eloquent scope cannot even see the other company's unit, so this goes in
        // as a raw insert — which is what the key carrying the client is there to stop.
        expect(fn () => DB::insert(
            'insert into org_units (tenant_id, parent_id, type, name, created_at, updated_at)
             values (?, ?, ?, ?, now(), now())',
            [$this->meridian->getKey(), $vertexRoot->getKey(), 'business_line', 'Corporate']
        ))->toThrow(QueryException::class, 'foreign key constraint');
    });
});

it('refuses the same code twice within one client company', function () {
    // Nothing follows the refused insert on purpose: Postgres abandons the surrounding
    // transaction when a constraint is violated, so any later query in the same test
    // would fail for that reason rather than its own.
    TenantContext::run($this->meridian, function () {
        OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.', 'code' => 'MLPL']);

        expect(fn () => OrgUnit::create(['type' => 'company', 'name' => 'Something else', 'code' => 'MLPL']))
            ->toThrow(QueryException::class);
    });
});

it('lets two client companies use the same code, and lets a unit have none', function () {
    TenantContext::run($this->meridian, fn () => OrgUnit::create([
        'type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.', 'code' => 'MLPL',
    ]));

    TenantContext::run($this->vertex, function () {
        OrgUnit::create(['type' => 'company', 'name' => 'Vertex Foods', 'code' => 'MLPL']);
        OrgUnit::create(['type' => 'region', 'name' => 'North']);
        OrgUnit::create(['type' => 'region', 'name' => 'South']);

        expect(OrgUnit::query()->count())->toBe(3);
    });
});
