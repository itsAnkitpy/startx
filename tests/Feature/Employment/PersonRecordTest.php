<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\Designation;
use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/*
| Who somebody is, as opposed to what their job is. The name in parts because letters,
| statutory forms and the directory handoff all ask for the parts; the personal address
| because every document owed after the last working day has to reach somebody whose
| account is already closed.
|
| The rule that an exit cannot close without a personal address or phone recorded
| belongs to the exit's own step, which is module 07 — there is no exit to close yet.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

it('keeps a name in parts and puts it back together on request', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::create([
            'first_name' => 'Priya',
            'middle_name' => 'Lakshmi',
            'last_name' => 'Nair',
            'preferred_name' => 'Pri',
            'date_of_birth' => '1994-02-17',
            'work_email' => 'priya@meridian.test',
            'password' => 'correct-horse',
        ]);

        expect($priya->name)->toBe('Priya Lakshmi Nair')
            ->and($priya->preferred_name)->toBe('Pri')
            ->and($priya->date_of_birth->toDateString())->toBe('1994-02-17')
            // Somebody with no middle name gets no double space.
            ->and(User::factory()->named('Rakesh Iyer')->create()->name)->toBe('Rakesh Iyer');
    });
});

it('round-trips a phone number with a leading zero and one with a country code', function () {
    // The old system stored these as integers, which silently destroys a leading zero
    // and any country code, and put a single global unique index on the column — which
    // breaks the first time two client companies employ the same person.
    // The same number at two client companies, which the old system's global unique
    // index would have refused.
    $meridianPriya = TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create([
            'personal_phone' => '09876543210',
            'personal_email' => 'priya@personal.test',
        ]);

        expect($priya->fresh()->personal_phone)->toBe('09876543210');

        return $priya;
    });

    TenantContext::run($this->vertex, function () use ($meridianPriya) {
        $priya = User::factory()->named('Priya Nair')->create([
            'personal_phone' => '09876543210',
            'personal_email' => 'priya@personal.test',
        ]);

        expect($priya->fresh()->personal_phone)->toBe('09876543210')
            ->and($priya->getKey())->not->toBe($meridianPriya->getKey());
    });

    TenantContext::run($this->meridian, function () {
        $withCountryCode = User::factory()->create(['personal_phone' => '+91 98765 43210']);

        expect($withCountryCode->fresh()->personal_phone)->toBe('+91 98765 43210');
    });
});

it('records when an account was switched off, and clears it on a rehire', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Iyer')->create();

        expect($rakesh->deactivated_at)->toBeNull();

        $rakesh->update(['active' => false]);
        expect($rakesh->fresh()->deactivated_at)->not->toBeNull();

        $rakesh->update(['active' => true]);
        expect($rakesh->fresh()->deactivated_at)->toBeNull();
    });
});

it('narrows a question about one person to the branch that person sits in', function () {
    TenantContext::run($this->meridian, function () {
        $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight', 'parent_id' => $company->getKey()]);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment', 'parent_id' => $company->getKey()]);

        // Anjali is HR head for Freight only.
        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create([
            'role_id' => Role::factory()->keyed('hr_head')->withPermissions([
                Permission::ViewPerson, Permission::UpdatePerson,
            ])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        $inFreight = User::factory()->named('Priya Nair')->create();
        EmploymentRecord::factory()->forPerson($inFreight)->in($freight)->create();

        $inRetail = User::factory()->named('Deepak Verma')->create();
        EmploymentRecord::factory()->forPerson($inRetail)->in($retail)->create();

        expect(Gate::forUser($anjali)->allows('view', $inFreight))->toBeTrue()
            ->and(Gate::forUser($anjali)->allows('update', $inFreight))->toBeTrue()
            ->and(Gate::forUser($anjali)->allows('view', $inRetail))->toBeFalse()
            ->and(Gate::forUser($anjali)->allows('update', $inRetail))->toBeFalse()
            // Opening a list is still asked without a person, so it stays true — the
            // rows in the list are each checked in turn, which is module 12's job.
            ->and(Gate::forUser($anjali)->allows('viewAny', User::class))->toBeTrue();
    });
});

it('falls back to holding the action anywhere for somebody with no job row yet', function () {
    // Every account is in this state before its first employment row is written, so it
    // cannot be a refusal.
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create([
            'role_id' => Role::factory()->withPermissions([Permission::ViewPerson])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        $newStarter = User::factory()->named('Chandni Bose')->create();

        expect($newStarter->lastKnownOrgUnit())->toBeNull()
            ->and(Gate::forUser($anjali)->allows('view', $newStarter))->toBeTrue();
    });
});

it('keeps a leaver inside the branch they left from', function () {
    // The day Rakesh leaves Retail, his file must not become readable by an HR head
    // who is responsible for Freight alone. It did: a person on their last working day
    // stops having a job row that is true today, and a question narrowed to "nowhere"
    // was being answered "then do not narrow it". Exits are the flow this product is
    // sold on, so a leaver's file was the one it guarded worst.
    TenantContext::run($this->meridian, function () {
        $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight', 'parent_id' => $company->getKey()]);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment', 'parent_id' => $company->getKey()]);

        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create([
            'role_id' => Role::factory()->keyed('hr_head')->withPermissions([
                Permission::ViewPerson, Permission::UpdatePerson, Permission::DeactivatePerson,
            ])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        $rakesh = User::factory()->named('Rakesh Iyer')->create();
        $job = EmploymentRecord::factory()->forPerson($rakesh)->in($retail)->effective('2024-04-01')->create();

        expect(Gate::forUser($anjali)->allows('view', $rakesh))->toBeFalse();

        // He exits on 30 September, which closes his last row and leaves him with none
        // that is true today.
        $job->update([
            'effective_to' => '2025-09-30',
            'last_working_day' => '2025-09-30',
            'employment_status' => 'exited',
        ]);

        $rakesh = $rakesh->fresh();
        app(PermissionResolver::class)->forget();

        expect($rakesh->currentEmployment)->toBeNull()
            // He still belongs to Retail, which is what the question narrows by.
            ->and($rakesh->lastKnownOrgUnit()->getKey())->toBe($retail->getKey())
            ->and(Gate::forUser($anjali)->allows('view', $rakesh))->toBeFalse()
            ->and(Gate::forUser($anjali)->allows('update', $rakesh))->toBeFalse()
            ->and(Gate::forUser($anjali)->allows('deactivate', $rakesh))->toBeFalse();
    });
});

it('records a person who has only one name', function () {
    // A required surname would have whoever is entering them inventing a second name,
    // which then prints on a letter. Both PAN and Aadhaar accept a single name, and the
    // directory handoff in module 11 treats the family name as optional too.
    TenantContext::run($this->meridian, function () {
        $priya = User::create([
            'first_name' => 'Priya',
            'work_email' => 'priya@meridian.test',
            'password' => 'correct-horse',
        ]);

        expect($priya->last_name)->toBeNull()
            // And the assembled name carries no trailing space.
            ->and($priya->fresh()->name)->toBe('Priya');
    });
});

it('projects onto the identity attributes a directory sync expects, with no transform', function () {
    // Module 11 provisions to Okta, Entra and Google, all of which speak SCIM. The
    // point of this test is that the payload is assembled from columns rather than
    // computed: if a bespoke transform were needed, the record would be the wrong shape.
    //
    // The designation completed this in step 5 and is the last attribute SCIM asks for —
    // sent as the words the job row froze rather than a live read of the list, so a
    // directory receives what the person's record says rather than what the list says
    // today. SCIM's own `costCenter` is simply not sent: it is an optional attribute and
    // this product holds no cost centre.
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $designation = Designation::factory()->named('Sr. Manager')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $priya = User::create([
            'first_name' => 'Priya', 'middle_name' => 'Lakshmi', 'last_name' => 'Nair',
            'preferred_name' => 'Pri', 'work_email' => 'priya@meridian.test',
            'personal_email' => 'priya@personal.test', 'personal_phone' => '+91 98765 43210',
            'timezone' => 'Asia/Kolkata', 'locale' => 'en_IN', 'password' => 'correct-horse',
        ]);

        $job = EmploymentRecord::factory()
            ->forPerson($priya)->in($freight)->reportingTo($anjali)->designated($designation)
            ->create(['employee_code' => 'MER-0041', 'employment_type' => 'full_time']);

        $payload = [
            'userName' => $priya->work_email,
            'name' => [
                'formatted' => $priya->name,
                'givenName' => $priya->first_name,
                'middleName' => $priya->middle_name,
                'familyName' => $priya->last_name,
            ],
            'displayName' => $priya->preferred_name,
            'title' => $job->recorded_designation_name,
            'userType' => $job->employment_type,
            'active' => $priya->active,
            'preferredLanguage' => $priya->locale,
            'timezone' => $priya->timezone,
            'emails' => [
                ['value' => $priya->work_email, 'type' => 'work', 'primary' => true],
                ['value' => $priya->personal_email, 'type' => 'home', 'primary' => false],
            ],
            'phoneNumbers' => [['value' => $priya->personal_phone, 'type' => 'mobile']],
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User' => [
                'employeeNumber' => $job->employee_code,
                'organization' => $job->orgUnit->name,
                'manager' => ['value' => (string) $job->reports_to_id, 'displayName' => $job->reportsTo->name],
            ],
        ];

        expect($payload['name'])->toBe([
            'formatted' => 'Priya Lakshmi Nair',
            'givenName' => 'Priya',
            'middleName' => 'Lakshmi',
            'familyName' => 'Nair',
        ])
            ->and($payload['userName'])->toBe('priya@meridian.test')
            ->and($payload['title'])->toBe('Sr. Manager')
            ->and($payload['timezone'])->toBe('Asia/Kolkata')
            ->and($payload['urn:ietf:params:scim:schemas:extension:enterprise:2.0:User'])->toBe([
                'employeeNumber' => 'MER-0041',
                'organization' => 'Freight',
                'manager' => ['value' => (string) $anjali->getKey(), 'displayName' => 'Anjali Rao'],
            ]);
    });
});
