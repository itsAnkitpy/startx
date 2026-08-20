<?php

use App\Exceptions\ReferenceListRefused;
use App\Models\Designation;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The two lists a client company maintains — the designations and the offices — and the
| four columns on the job row that point at them.
|
| The point of the whole step is the pair of frozen copies. A job row holds the link *and*
| the words it read, so Anjali can tidy Meridian's designation list any afternoon she likes
| without rewriting what Rakesh's closed exit says he was.
|
| A refused insert abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own, so each expected database refusal gets a test to
| itself.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

/**
 * Priya, employed at Meridian's Freight line as a "Sr. Manager" out of the Shimla office.
 *
 * @return array{0: User, 1: Designation, 2: Office, 3: EmploymentRecord}
 */
function priyaAsSeniorManager(): array
{
    $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

    $designation = Designation::factory()->named('Sr. Manager')->create();
    $office = Office::factory()->named('Shimla')->create(['state_code' => 'IN-HP']);

    $priya = User::factory()->named('Priya Nair')->create();

    $row = EmploymentRecord::factory()
        ->forPerson($priya)
        ->in($freight)
        ->designated($designation)
        ->basedAt($office)
        ->effective('2024-04-01')
        ->create();

    return [$priya, $designation, $office, $row];
}

it('keeps what a job row said when the designation is renamed underneath it', function () {
    TenantContext::run($this->meridian, function () {
        [, $designation, , $row] = priyaAsSeniorManager();

        expect($row->recorded_designation_name)->toBe('Sr. Manager');

        // Anjali tidies the list months later. This is the ordinary thing a client does,
        // and it must not reach backwards into a record already written.
        $designation->update(['name' => 'Senior Manager']);

        expect($row->fresh()->recorded_designation_name)->toBe('Sr. Manager')
            // The link still points at the same entry, which now reads the new way — so
            // the picker shows the tidied name and the history shows the old one.
            ->and($row->fresh()->designation->name)->toBe('Senior Manager');
    });
});

it('keeps the state a job row recorded when the office is corrected underneath it', function () {
    TenantContext::run($this->meridian, function () {
        [, , $office, $row] = priyaAsSeniorManager();

        expect($row->recorded_office_state_code)->toBe('IN-HP');

        // Somebody had filed Shimla under the wrong state. Correcting it is right, and
        // professional tax follows the state a person worked in, so it must not rewrite
        // what every past row claims.
        $office->update(['state_code' => 'IN-PB']);

        expect($row->fresh()->recorded_office_state_code)->toBe('IN-HP')
            ->and($row->fresh()->office->state_code)->toBe('IN-PB');
    });
});

it('keeps the country a job row recorded when the office entry is reused for another country', function () {
    // The state cannot carry this on its own: an office outside India has no state to
    // freeze, so a row written in London froze nothing at all. Meridian closes London and
    // reuses the entry for Dublin, which is what people do instead of switching one off
    // and adding another — and every row that named it must stay in the United Kingdom.
    TenantContext::run($this->meridian, function () {
        $london = Office::factory()->named('London')->in('GB')->create();

        $row = EmploymentRecord::factory()
            ->forPerson(User::factory()->named('Priya Nair')->create())
            ->in(OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']))
            ->basedAt($london)
            ->effective('2024-04-01')
            ->create();

        expect($row->recorded_office_country)->toBe('GB')
            ->and($row->recorded_office_state_code)->toBeNull();

        $london->update(['name' => 'Dublin', 'country' => 'IE']);

        expect($row->fresh()->recorded_office_country)->toBe('GB')
            ->and($row->fresh()->office->country)->toBe('IE');
    });
});

it('reads a person as of a past date with the designation that was live then', function () {
    TenantContext::run($this->meridian, function () {
        [$priya, $designation, $office, $first] = priyaAsSeniorManager();

        $first->update(['effective_to' => '2026-03-31']);

        $director = Designation::factory()->named('Director')->create();

        EmploymentRecord::factory()
            ->forPerson($priya)
            ->in(OrgUnit::first())
            ->designated($director)
            ->basedAt($office)
            ->effective('2026-04-01')
            ->create();

        // The list is then tidied, after both rows exist.
        $designation->update(['name' => 'Senior Manager']);

        $lastYear = EmploymentRecord::query()->where('user_id', $priya->getKey())->asOf('2025-06-01')->first();
        $today = EmploymentRecord::query()->where('user_id', $priya->getKey())->asOf('2026-06-01')->first();

        expect($lastYear->recorded_designation_name)->toBe('Sr. Manager')
            ->and($today->recorded_designation_name)->toBe('Director');
    });
});

it('copies the words itself and ignores a submitted one', function () {
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $designation = Designation::factory()->named('Sr. Manager')->create();
        $priya = User::factory()->named('Priya Nair')->create();

        // Somebody submits a designation of their own choosing beside the real link. It
        // answers "what did this row read at the time", so a submitted field cannot be
        // allowed to answer it — the same rule as the field naming who entered the row.
        $row = EmploymentRecord::create([
            'user_id' => $priya->getKey(),
            'org_unit_id' => $freight->getKey(),
            'designation_id' => $designation->getKey(),
            'recorded_designation_name' => 'Director',
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);

        expect($row->recorded_designation_name)->toBe('Sr. Manager');
    });
});

it('leaves both copies empty where the job row names neither list', function () {
    TenantContext::run($this->meridian, function () {
        $row = EmploymentRecord::factory()
            ->forPerson(User::factory()->named('Priya Nair')->create())
            ->in(OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']))
            ->create();

        expect($row->designation_id)->toBeNull()
            ->and($row->recorded_designation_name)->toBeNull()
            ->and($row->office_id)->toBeNull()
            ->and($row->recorded_office_country)->toBeNull()
            ->and($row->recorded_office_state_code)->toBeNull();
    });
});

it('keeps a switched-off designation readable on every job row that named it', function () {
    TenantContext::run($this->meridian, function () {
        [, $designation, , $row] = priyaAsSeniorManager();

        // Meridian stops using the designation. Out of the picker, and still on the record.
        $designation->update(['active' => false]);

        expect(Designation::query()->where('active', true)->count())->toBe(0)
            ->and($row->fresh()->recorded_designation_name)->toBe('Sr. Manager')
            ->and($row->fresh()->designation->name)->toBe('Sr. Manager');
    });
});

it('refuses to delete a list entry, because a delete would take it out of somebody\'s history', function () {
    TenantContext::run($this->meridian, function () {
        $designation = Designation::factory()->named('Sr. Manager')->create();
        $office = Office::factory()->named('Shimla')->create();

        expect(fn () => $designation->delete())
            ->toThrow(ReferenceListRefused::class, 'cannot be deleted');

        expect(fn () => $office->delete())
            ->toThrow(ReferenceListRefused::class, 'only switched off');

        expect(Designation::query()->count())->toBe(1)
            ->and(Office::query()->count())->toBe(1);
    });
});

it('lets two client companies each hold a designation of the same name', function () {
    TenantContext::run($this->meridian, fn () => Designation::factory()->named('Senior Manager')->create());
    TenantContext::run($this->vertex, fn () => Designation::factory()->named('Senior Manager')->create());

    TenantContext::run($this->meridian, function () {
        expect(Designation::query()->count())->toBe(1);
    });
});

it('refuses the same designation name twice in one client company', function () {
    TenantContext::run($this->meridian, function () {
        Designation::factory()->named('Senior Manager')->create();
        Designation::factory()->named('Senior Manager')->create();
    });
})->throws(QueryException::class, 'designations_name_per_tenant');

it('refuses the same designation name in a different case', function () {
    // "Senior Manager" and "senior manager" are the same designation to everybody except
    // a plain unique index, and two of them is a dropdown nobody can choose from.
    TenantContext::run($this->meridian, function () {
        Designation::factory()->named('Senior Manager')->create();
        Designation::factory()->named('senior manager')->create();
    });
})->throws(QueryException::class, 'designations_name_per_tenant');

it('refuses a name already taken by a switched-off row', function () {
    // Counting switched-off rows on purpose: a client re-adding a name they retired is
    // sent back to the row their own history already points at, rather than opening a
    // second row beside it and splitting one designation across two.
    TenantContext::run($this->meridian, function () {
        Designation::factory()->named('Senior Manager')->switchedOff()->create();
        Designation::factory()->named('Senior Manager')->create();
    });
})->throws(QueryException::class, 'designations_name_per_tenant');

it('refuses a name that is the same but for a space', function () {
    // The hole the case-insensitive rule left, found on reviewing the finished code:
    // lowercasing does not trim, so a pasted list holding "Senior Manager" and
    // "Senior Manager " became two rows that look identical in a picker — the exact
    // outcome the rule exists to prevent, and the padding then travels onto every job
    // row that freezes the name.
    TenantContext::run($this->meridian, function () {
        Designation::factory()->named('Senior Manager ')->create();
    });
})->throws(QueryException::class, 'designations_name_not_blank_or_padded');

it('refuses a blank name on either list', function () {
    // A blank one travelled furthest: the job row froze an empty name, the rule that a
    // link must carry its name accepted it because an empty string is not nothing, and an
    // empty designation reached the directory sync and the letters.
    TenantContext::run($this->meridian, function () {
        Designation::factory()->named('')->create();
    });
})->throws(QueryException::class, 'designations_name_not_blank_or_padded');

it('refuses a blank office name too', function () {
    TenantContext::run($this->meridian, function () {
        Office::factory()->named('   ')->create();
    });
})->throws(QueryException::class, 'offices_name_not_blank_or_padded');

it('says which list entry is missing rather than blaming the frozen copy', function () {
    // A job row naming another client company's designation, or a number that is not
    // there at all, is refused by name before the insert. The key would refuse it anyway,
    // but the database's complaint is that the row has a link with no name frozen beside
    // it — which sends whoever is debugging an import to the write path rather than to the
    // wrong number in their file. Being a refusal rather than a database error, it also
    // leaves the surrounding transaction alive.
    $vertexDesignation = TenantContext::run($this->vertex, fn () => Designation::factory()->named('Director')->create());

    TenantContext::run($this->meridian, function () use ($vertexDesignation) {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $priya = User::factory()->named('Priya Nair')->create();

        $write = fn (int $designationId) => EmploymentRecord::factory()
            ->forPerson($priya)->in($freight)
            ->state(['designation_id' => $designationId])
            ->effective('2024-04-01')
            ->create();

        expect(fn () => $write($vertexDesignation->getKey()))
            ->toThrow(ReferenceListRefused::class, 'has no designation numbered')
            ->and(fn () => $write(9_999))
            ->toThrow(ReferenceListRefused::class, 'has no designation numbered');

        // The transaction survived it, which a database error would not have.
        expect(Designation::query()->count())->toBe(0);
    });
});

it('refuses the same office name twice in one client company', function () {
    TenantContext::run($this->meridian, function () {
        Office::factory()->named('Shimla')->create();
        Office::factory()->named('SHIMLA')->create();
    });
})->throws(QueryException::class, 'offices_name_per_tenant');

it('takes an office outside India with no state and no schema change', function () {
    TenantContext::run($this->meridian, function () {
        $london = Office::factory()->named('London')->in('GB')->create([
            'address_block' => "4th Floor, 12 Bishopsgate\nLondon EC2N 4AJ\nUnited Kingdom",
        ]);

        expect($london->state_code)->toBeNull()
            ->and($london->country)->toBe('GB')
            ->and($london->address_block)->toContain('EC2N 4AJ');

        // And a state that does exist is held as its ISO 3166-2 code, which is the form
        // module 11's payroll handoff has to name.
        $california = Office::factory()->named('San Jose')->in('US', 'US-CA')->create();

        expect($california->state_code)->toBe('US-CA');
    });
});

it('refuses a state that is not an ISO 3166-2 code', function () {
    // 'HP' is what somebody types, and it is not an ISO 3166-2 code — those name the
    // country too. Without this check the column called state_code holds it happily, and
    // module 11's payroll handoff is what discovers the difference. Deliberately a value
    // short enough to fit the column, so the check is what refuses it rather than the
    // column's own length.
    TenantContext::run($this->meridian, function () {
        Office::factory()->named('Shimla')->create(['state_code' => 'HP']);
    });
})->throws(QueryException::class, 'offices_state_is_iso_3166_2');

it('refuses a state code belonging to a different country than the office', function () {
    TenantContext::run($this->meridian, function () {
        Office::factory()->named('London')->create(['country' => 'GB', 'state_code' => 'IN-HP']);
    });
})->throws(QueryException::class, 'offices_state_is_iso_3166_2');

it('refuses a job row that names a designation with no words frozen beside it', function () {
    // The database refuses a link with no copy, rather than trusting every write path
    // that ever touches this table to remember the pair.
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $designation = Designation::factory()->named('Sr. Manager')->create();
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('employment_records')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'org_unit_id' => $freight->getKey(),
            'designation_id' => $designation->getKey(),
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);
    });
})->throws(QueryException::class, 'employment_records_designation_name_frozen');

it('refuses a job row that names an office with no country frozen beside it', function () {
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $office = Office::factory()->named('Shimla')->create();
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('employment_records')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'org_unit_id' => $freight->getKey(),
            'office_id' => $office->getKey(),
            'recorded_office_state_code' => 'IN-HP',
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);
    });
})->throws(QueryException::class, 'employment_records_office_copy_frozen');

it('refuses a frozen state with no frozen country beside it', function () {
    // The half of the rule a plainly written check would wave through: a check whose
    // expression comes out null passes, so comparing the state's country half to a null
    // country has to be written as `is not distinct from` rather than `=`.
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('employment_records')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'org_unit_id' => $freight->getKey(),
            'recorded_office_state_code' => 'IN-HP',
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);
    });
})->throws(QueryException::class, 'employment_records_office_copy_frozen');

it('refuses a job row naming another client company\'s designation', function () {
    // Written as a raw insert with the Eloquent scope out of the way, because the key is
    // what has to refuse this: Postgres ignores row-level policies while it checks a
    // reference, so a key on the id alone would accept it.
    $vertexDesignation = TenantContext::run($this->vertex, fn () => Designation::factory()->named('Director')->create());

    TenantContext::run($this->meridian, function () use ($vertexDesignation) {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('employment_records')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'org_unit_id' => $freight->getKey(),
            'designation_id' => $vertexDesignation->getKey(),
            'recorded_designation_name' => 'Director',
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);
    });
})->throws(QueryException::class, 'foreign key constraint');

it('refuses a job row naming another client company\'s office', function () {
    $vertexOffice = TenantContext::run($this->vertex, fn () => Office::factory()->named('Pune')->create());

    TenantContext::run($this->meridian, function () use ($vertexOffice) {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('employment_records')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'org_unit_id' => $freight->getKey(),
            'office_id' => $vertexOffice->getKey(),
            // Frozen copy filled in on purpose, so the key is what refuses this rather
            // than the rule that a link must carry a copy.
            'recorded_office_country' => 'IN',
            'employment_type' => 'full_time',
            'employment_status' => 'confirmed',
            'joining_date' => '2024-04-01',
            'effective_from' => '2024-04-01',
        ]);
    });
})->throws(QueryException::class, 'foreign key constraint');

it('keeps one client company\'s lists invisible to another, including through a raw query', function () {
    TenantContext::run($this->meridian, function () {
        Designation::factory()->named('Sr. Manager')->create();
        Office::factory()->named('Shimla')->create();
    });

    TenantContext::run($this->vertex, function () {
        expect(Designation::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0)
            ->and(DB::table('designations')->count())->toBe(0)
            ->and(DB::table('offices')->count())->toBe(0);
    });
});
