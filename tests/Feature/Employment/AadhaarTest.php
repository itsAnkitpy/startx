<?php

use App\Models\AadhaarVerification;
use App\Models\EmployeeAsset;
use App\Models\EmployeeStatutoryId;
use App\Models\EmploymentRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Three facts about an employee's Aadhaar and no more: that the document was seen, the
| four digits the masked form itself shows, and the consent that was taken. Never the
| number, never the scan.
|
| All three live in one table so that there is a single place to point at when proving
| what is kept, a single row to empty when consent is withdrawn, one permission to
| grant, and one table to keep out of every export.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

/**
 * Every column in the database that could hold text, so a scan can prove nothing
 * Aadhaar-shaped is sitting anywhere.
 *
 * @return list<object{table_name: string, column_name: string}>
 */
function textColumns(): array
{
    /** @var list<object{table_name: string, column_name: string}> $columns */
    $columns = DB::select(
        "select c.table_name, c.column_name
           from information_schema.columns c
           join information_schema.tables t
             on t.table_schema = c.table_schema and t.table_name = c.table_name
          where c.table_schema = current_schema()
            and t.table_type = 'BASE TABLE'
            and c.data_type in ('character varying', 'text')
          order by c.table_name, c.column_name"
    );

    return $columns;
}

it('records that the document was seen and that consent was given, not the number', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        $verification = AadhaarVerification::create([
            'user_id' => $priya->getKey(),
            'verified_at' => now(),
            'verified_by' => $anjali->getKey(),
            'last_four' => '4444',
            'notice_version' => '2026-08-aadhaar-notice-v1',
            'consented_at' => now(),
            'consent_channel' => 'candidate_portal',
        ]);

        expect($verification->last_four)->toBe('4444')
            ->and($verification->notice_version)->toBe('2026-08-aadhaar-notice-v1')
            ->and($verification->consent_channel)->toBe('candidate_portal')
            ->and($verification->consented_at)->not->toBeNull()
            ->and($priya->aadhaarVerification->getKey())->toBe($verification->getKey())
            // There is nowhere on this table for a number to go.
            ->and(array_keys($verification->getAttributes()))->not->toContain('number');
    });
});

it('refuses more than four digits in the masked column, at the database', function () {
    // The four digits are what UIDAI's own masked Aadhaar shows. The column cannot hold
    // a number even if every check above it were removed — this one is refused by the
    // column's own width, and the check constraint below refuses everything that is the
    // right length but not digits.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        DB::table('aadhaar_verifications')->insert([
            'tenant_id' => $this->meridian->getKey(),
            'user_id' => $priya->getKey(),
            'last_four' => '44444',
            'notice_version' => 'v1',
            'consented_at' => now(),
            'consent_channel' => 'candidate_portal',
        ]);
    });
})->throws(QueryException::class);

it('refuses to record that a document was seen without recording the consent for it', function () {
    // The consent is not an optional extra on this table — the notice version, the
    // moment and the channel are all required, so there is no way to hold a
    // verification with no lawful basis recorded beside it.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        AadhaarVerification::create([
            'user_id' => $priya->getKey(),
            'verified_at' => now(),
            'last_four' => '4444',
        ]);
    });
})->throws(QueryException::class);

it('refuses anything but digits in the masked column', function () {
    // Four characters fit the column, so only the check constraint stands between this
    // and a masked field holding something that is not a masked Aadhaar at all.
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        AadhaarVerification::create([
            'user_id' => $priya->getKey(), 'last_four' => 'ABCD', 'notice_version' => 'v1',
            'consented_at' => now(), 'consent_channel' => 'candidate_portal',
        ]);
    });
})->throws(QueryException::class);

it('keeps one row per person', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create();

        $row = [
            'user_id' => $priya->getKey(), 'notice_version' => 'v1',
            'consented_at' => now(), 'consent_channel' => 'candidate_portal',
        ];

        AadhaarVerification::create($row);
        AadhaarVerification::create($row);
    });
})->throws(QueryException::class);

it('empties the row when consent is withdrawn and leaves the exit with everything it needs', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->named('Priya Nair')->create(['personal_email' => 'priya@personal.test']);
        EmploymentRecord::factory()->forPerson($priya)->create();
        EmployeeStatutoryId::create(['user_id' => $priya->getKey(), 'type' => 'pan', 'value' => 'ABCPN1234F']);
        EmployeeAsset::create(['user_id' => $priya->getKey(), 'asset_type' => 'laptop', 'issued_at' => '2024-06-11']);

        $verification = AadhaarVerification::create([
            'user_id' => $priya->getKey(),
            'verified_at' => now(),
            'last_four' => '4444',
            'notice_version' => 'v1',
            'consented_at' => now(),
            'consent_channel' => 'candidate_portal',
        ]);

        $verification->withdrawConsent();

        expect($verification->fresh()->last_four)->toBeNull()
            ->and($verification->fresh()->verified_at)->toBeNull()
            ->and($verification->fresh()->consent_withdrawn_at)->not->toBeNull()
            // The record that consent was given and then withdrawn is itself the
            // evidence the withdrawal has to leave behind.
            ->and($verification->fresh()->consented_at)->not->toBeNull();

        // Nothing an exit needs is held here, so emptying it cannot block a settlement
        // or a letter. Until the exit flow exists (module 07), what can be shown is
        // that everything the exit reads is untouched.
        expect($priya->fresh()->personal_email)->toBe('priya@personal.test')
            ->and($priya->currentEmployment)->not->toBeNull()
            ->and($priya->statutoryIds()->count())->toBe(1)
            ->and($priya->assets()->count())->toBe(1);
    });
});

it('holds every Aadhaar fact in one table, and nothing Aadhaar-shaped anywhere else', function () {
    TenantContext::run($this->meridian, function () {
        // No other table even has a column for it.
        $elsewhere = collect(textColumns())
            ->filter(fn (object $column) => $column->table_name !== 'aadhaar_verifications')
            ->filter(fn (object $column) => str_contains(strtolower($column->column_name), 'aadhaar'))
            ->map(fn (object $column) => "{$column->table_name}.{$column->column_name}")
            ->values()
            ->all();

        expect($elsewhere)->toBe([]);

        // A person with everything filled in, including the fields somebody might try
        // to hide a number in.
        $priya = User::factory()->named('Priya Nair')->create([
            'personal_phone' => '+91 98765 43210',
        ]);
        EmploymentRecord::factory()->forPerson($priya)->create(['change_reason' => 'joined']);
        EmployeeStatutoryId::create(['user_id' => $priya->getKey(), 'type' => 'pan', 'value' => 'ABCPN1234F']);
        EmployeeAsset::create([
            'user_id' => $priya->getKey(), 'asset_type' => 'laptop', 'issued_at' => '2024-06-11',
            'issue_condition_note' => 'New, no marks.',
        ]);

        $verification = AadhaarVerification::create([
            'user_id' => $priya->getKey(), 'verified_at' => now(), 'last_four' => '4444',
            'notice_version' => 'v1', 'consented_at' => now(), 'consent_channel' => 'candidate_portal',
        ]);

        $verification->delete();

        // Deleting that one row is the whole erasure. Scan every text column in the
        // database for anything with the shape of an Aadhaar number.
        $shaped = [];

        foreach (textColumns() as $column) {
            $values = DB::table($column->table_name)->pluck($column->column_name)->filter()->all();

            foreach ($values as $value) {
                if (AadhaarVerification::looksLikeANumber((string) $value)) {
                    $shaped[] = "{$column->table_name}.{$column->column_name}";
                }
            }
        }

        expect($shaped)->toBe([])
            ->and(AadhaarVerification::query()->count())->toBe(0)
            ->and($priya->fresh()->aadhaarVerification)->toBeNull();
    });
});
