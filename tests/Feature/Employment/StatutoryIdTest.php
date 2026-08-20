<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\EmployeeRecordRefused;
use App\Models\EmployeeStatutoryId;
use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| The identifiers a client has to hand to payroll or print on a letter: tax number,
| provident-fund number, bank account, passport. Held as rows typed by kind, encrypted,
| hidden from anything that serialises a record, and behind their own permission —
| seeing somebody's record is not the same as seeing their bank account.
|
| A twelve-digit number that passes the Aadhaar check digit is refused outright, which
| is what keeps "we do not hold your employees' Aadhaar numbers" true.
*/

/**
 * Twelve digits, a leading 2, and a Verhoeff check digit that matches — the shape of a
 * real Aadhaar number, and obviously nobody's.
 */
const AADHAAR_SHAPED = '222233334444';

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

it('stores the value encrypted and keeps it out of a serialised record', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        $pan = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(),
            'type' => 'pan',
            'value' => 'ABCPN1234F',
        ]);

        $stored = DB::table('employee_statutory_ids')->where('id', $pan->getKey())->value('value');

        expect($stored)->not->toBe('ABCPN1234F')
            ->and(strlen((string) $stored))->toBeGreaterThan(20)
            // Read back through the model it is the value again.
            ->and($pan->fresh()->value)->toBe('ABCPN1234F')
            // And anything that turns a record into an array or JSON — an export, an
            // API response, a log line — does not carry it.
            ->and($pan->fresh()->toArray())->not->toHaveKey('value');
    });
});

it('keeps a leaver\'s bank account withheld outside the branch they left from', function () {
    // The sharpest version of the same rule as the person's own file: a bank account is
    // never more in demand than in the fortnight after somebody leaves, which is
    // exactly when the branch they belonged to stopped being readable from their job
    // row. Anjali is HR head for Freight; Rakesh left Retail.
    TenantContext::run($this->meridian, function () {
        $company = OrgUnit::create(['type' => 'company', 'name' => 'Meridian Logistics Pvt. Ltd.']);
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight', 'parent_id' => $company->getKey()]);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment', 'parent_id' => $company->getKey()]);

        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create([
            'role_id' => Role::factory()->keyed('hr_head')->withPermissions([
                Permission::ViewStatutoryId,
            ])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        $rakesh = User::factory()->named('Rakesh Iyer')->create();
        $job = EmploymentRecord::factory()->forPerson($rakesh)->in($retail)->effective('2024-04-01')->create();

        $account = EmployeeStatutoryId::create([
            'user_id' => $rakesh->getKey(),
            'type' => 'bank_account',
            'value' => '50100234567890',
        ]);

        expect($account->valueFor($anjali))->toBe(EmployeeStatutoryId::Withheld);

        $job->update(['effective_to' => '2025-09-30', 'employment_status' => 'exited']);
        app(PermissionResolver::class)->forget();

        expect(EmployeeStatutoryId::find($account->getKey())->valueFor($anjali))
            ->toBe(EmployeeStatutoryId::Withheld);
    });
});

it('tells a reader without the permission that an identifier is on file, without showing it', function () {
    // The distinction that matters: "nothing on file" and "not yours to see" must not
    // look identical, or somebody enters the same identifier a second time. Rippling
    // says the field was withheld rather than omitting it, and this is that shape.
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);

        $priya = User::factory()->named('Priya Nair')->create();
        EmploymentRecord::factory()->forPerson($priya)->in($freight)->create();

        $pan = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'pan', 'value' => 'ABCPN1234F',
        ]);

        $payroll = User::factory()->named('Anjali Rao')->create();
        $manager = User::factory()->named('Deepak Verma')->create();

        $payroll->roleAssignments()->create([
            'role_id' => Role::factory()->withPermissions([Permission::ViewStatutoryId])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);
        $manager->roleAssignments()->create([
            'role_id' => Role::factory()->withPermissions([Permission::ViewPerson])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        expect($pan->valueFor($payroll))->toBe('ABCPN1234F')
            ->and($pan->valueFor($manager))->toBe(EmployeeStatutoryId::Withheld)
            // The manager can still see that a tax number exists at all.
            ->and($priya->statutoryIds()->pluck('type')->all())->toBe(['pan']);
    });
});

it('narrows the identifier permission to the branch the person sits in', function () {
    TenantContext::run($this->meridian, function () {
        $freight = OrgUnit::create(['type' => 'business_line', 'name' => 'Freight']);
        $retail = OrgUnit::create(['type' => 'business_line', 'name' => 'Retail Fulfilment']);

        $priya = User::factory()->named('Priya Nair')->create();
        EmploymentRecord::factory()->forPerson($priya)->in($retail)->create();

        $pan = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'pan', 'value' => 'ABCPN1234F',
        ]);

        // Anjali handles payroll for Freight. Priya is in Retail.
        $anjali = User::factory()->named('Anjali Rao')->create();
        $anjali->roleAssignments()->create([
            'role_id' => Role::factory()->withPermissions([Permission::ViewStatutoryId])->create()->getKey(),
            'org_unit_id' => $freight->getKey(),
        ]);

        expect($pan->valueFor($anjali))->toBe(EmployeeStatutoryId::Withheld);
    });
});

it('adds a second country\'s identifier as rows, with no migration', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'pan', 'country' => 'IN', 'value' => 'ABCPN1234F',
        ]);
        EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'passport', 'country' => 'AE', 'value' => 'P1234567',
        ]);

        expect($priya->statutoryIds()->pluck('country')->all())->toBe(['IN', 'AE']);
    });
});

it('refuses an Aadhaar number written into a tax number field', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'pan', 'value' => AADHAAR_SHAPED,
        ]);
    });
})->throws(EmployeeRecordRefused::class);

it('refuses an Aadhaar number written into a passport field', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'passport', 'value' => AADHAAR_SHAPED,
        ]);
    });
})->throws(EmployeeRecordRefused::class);

it('refuses an Aadhaar number spaced out the way the card prints it', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'driving_licence', 'value' => '2222 3333 4444',
        ]);
    });
})->throws(EmployeeRecordRefused::class);

it('keeps the refused number out of the exception it throws', function () {
    // The refusal exists so the number is never stored. An exception that carried it
    // would put it straight into the log file, which is storage too. PHP prints array
    // arguments in a stack trace as "Array", and this machine has argument printing
    // switched on, so this is the case that would leak if the message or a scalar frame
    // ever held the value.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        try {
            EmployeeStatutoryId::create([
                'user_id' => $priya->getKey(), 'type' => 'pan', 'value' => AADHAAR_SHAPED,
            ]);

            $this->fail('The Aadhaar number was accepted.');
        } catch (EmployeeRecordRefused $refusal) {
            expect($refusal->getMessage())->not->toContain(AADHAAR_SHAPED)
                ->and($refusal->getTraceAsString())->not->toContain(AADHAAR_SHAPED)
                ->and((string) $refusal)->not->toContain(AADHAAR_SHAPED)
                // And nothing of the number survives spacing either.
                ->and((string) $refusal)->not->toContain('2222 3333 4444');
        }
    });
});

it('refuses an identifier of a kind this product does not hold', function () {
    // There is no Aadhaar kind, and an invented kind is refused — so the number cannot
    // be stored under an honest label either.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'aadhaar', 'value' => '1234',
        ]);
    });
})->throws(EmployeeRecordRefused::class);

it('accepts the two identifiers that are genuinely twelve digits long', function () {
    // A provident-fund universal account number is twelve digits, and Indian bank
    // account numbers of twelve digits are ordinary. Roughly one real value in ten
    // passes the Aadhaar check digit by chance, so refusing here would reject genuine
    // numbers. This is a stated gap: somebody determined can still hide an Aadhaar
    // number in these two fields, and what the check closes is the case that actually
    // happens — a number pasted into whichever field was open.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        $uan = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'universal_account_number', 'value' => AADHAAR_SHAPED,
        ]);
        $bank = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'bank_account', 'value' => '123456789012',
        ]);

        expect($uan->exists)->toBeTrue()->and($bank->exists)->toBeTrue();
    });
});

it('leaves a real twelve-digit number that is not Aadhaar-shaped alone', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        // Twelve digits, but the check digit does not match, so it is not an Aadhaar
        // number and nothing here should object to it.
        $pf = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'provident_fund', 'value' => '222233334445',
        ]);

        // And twelve digits with a matching check digit, but beginning with 1 — which
        // no Aadhaar number does. This is what the leading-digit rule is for: without
        // it, a provident-fund number of this shape would be refused as an Aadhaar
        // number, and the provident-fund field is one the check does apply to.
        $second = EmployeeStatutoryId::create([
            'user_id' => $priya->getKey(), 'type' => 'state_insurance', 'value' => '100123456782',
        ]);

        expect($pf->exists)->toBeTrue()->and($second->exists)->toBeTrue();
    });
});
