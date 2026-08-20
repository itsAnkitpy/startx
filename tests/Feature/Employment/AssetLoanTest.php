<?php

use App\Models\EmployeeAsset;
use App\Models\OrgUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
| A laptop loan is a dated row, not a status. The disputed-settlement argument this
| product is sold on is an argument about whether a laptop came back in July, and a
| status cannot answer that once the exit is closed.
|
| The condition is recorded at each end because that is the other half of the same
| argument: whether it came back is one question, and what state it came back in is
| another.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

it('answers a dispute raised after the exit from the loan and return dates', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Iyer')->create();
        $rohit = User::factory()->named('Rohit Sharma')->create();
        $itSupport = OrgUnit::create(['type' => 'sub_business_line', 'name' => 'IT Support']);

        $laptop = EmployeeAsset::create([
            'user_id' => $rakesh->getKey(),
            'asset_type' => 'laptop',
            'identifier' => 'MER-LT-0912',
            'org_unit_id' => $itSupport->getKey(),
            'issued_at' => '2024-06-11',
            'issued_by' => $rohit->getKey(),
            'returned_at' => '2026-07-14',
            'returned_to' => $rohit->getKey(),
        ]);

        // Six weeks after the exit, somebody asks when it came back and who took it.
        $held = $rakesh->assets()->sole();

        expect($held->getKey())->toBe($laptop->getKey())
            ->and($held->issued_at->toDateString())->toBe('2024-06-11')
            ->and($held->returned_at->toDateString())->toBe('2026-07-14')
            ->and($held->returnedTo->name)->toBe('Rohit Sharma')
            ->and($held->orgUnit->name)->toBe('IT Support');
    });
});

it('keeps the state at issue and the state at return as two separate facts', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Iyer')->create();

        $laptop = EmployeeAsset::create([
            'user_id' => $rakesh->getKey(),
            'asset_type' => 'laptop',
            'identifier' => 'MER-LT-0912',
            'issued_at' => '2024-06-11',
            'issue_condition_note' => 'New, no marks, charger and sleeve included.',
        ]);

        // Two years later it comes back, and whoever receives it writes what they see.
        $laptop->update([
            'returned_at' => '2026-07-14',
            'return_condition_note' => 'Screen cracked, sleeve missing.',
        ]);

        // Neither note has overwritten the other, which is what a recovery charge is
        // argued from: it went out undamaged and came back cracked.
        expect($laptop->fresh()->issue_condition_note)->toBe('New, no marks, charger and sleeve included.')
            ->and($laptop->fresh()->return_condition_note)->toBe('Screen cracked, sleeve missing.');
    });
});

it('shows equipment still out as a row with no return date', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Iyer')->create();

        EmployeeAsset::create([
            'user_id' => $rakesh->getKey(), 'asset_type' => 'laptop', 'issued_at' => '2024-06-11',
        ]);
        EmployeeAsset::create([
            'user_id' => $rakesh->getKey(), 'asset_type' => 'access_card',
            'issued_at' => '2024-06-11', 'returned_at' => '2026-07-14',
        ]);

        expect($rakesh->assets()->whereNull('returned_at')->pluck('asset_type')->all())->toBe(['laptop']);
    });
});

it('refuses a return dated before the loan', function () {
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->named('Rakesh Iyer')->create();

        EmployeeAsset::create([
            'user_id' => $rakesh->getKey(),
            'asset_type' => 'laptop',
            'issued_at' => '2026-07-14',
            'returned_at' => '2024-06-11',
        ]);
    });
})->throws(QueryException::class);
