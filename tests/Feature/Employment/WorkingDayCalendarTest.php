<?php

use App\Exceptions\ReferenceListRefused;
use App\Models\Office;
use App\Models\OfficeHoliday;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The working-day calendar an office owns, and the counting the product's central deadline
| depends on.
|
| The number under test is the one the whole product is sold on. Rakesh resigns on a
| Friday and the law gives Meridian two working days to pay him; the answer is the
| Tuesday, and forty-eight hours would have said Sunday. Every test here is a date, not a
| mechanism.
|
| A refused insert abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own, so each expected database refusal gets a test to
| itself.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

it('counts two working days from a Friday to the Tuesday', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        // Friday 14 August 2026.
        expect($shimla->addWorkingDays(new DateTimeImmutable('2026-08-14'), 2)->toDateString())
            ->toBe('2026-08-18');
    });
});

it('counts two working days to the Wednesday when the Monday is a recorded holiday', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'Independence Day observed')->create();

        expect($shimla->addWorkingDays(new DateTimeImmutable('2026-08-14'), 2)->toDateString())
            ->toBe('2026-08-19');
    });
});

it('gives two offices of the same client different deadlines when only one keeps the festival day', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create(['state_code' => 'IN-HP']);
        $bengaluru = Office::factory()->named('Bengaluru')->create(['state_code' => 'IN-KA']);

        // Karnataka keeps its state formation day; Himachal Pradesh does not.
        OfficeHoliday::factory()->at($bengaluru)->on('2026-11-02', 'Kannada Rajyotsava observed')->create();

        $resignedOn = new DateTimeImmutable('2026-10-30');

        expect($shimla->addWorkingDays($resignedOn, 2)->toDateString())->toBe('2026-11-03')
            ->and($bengaluru->addWorkingDays($resignedOn, 2)->toDateString())->toBe('2026-11-04');
    });
});

it('counts correctly for an office whose weekend is Friday and Saturday', function () {
    TenantContext::run($this->meridian, function () {
        $dubai = Office::factory()->named('Dubai')->in('AE')->weekendOn([5, 6])->create();

        // Thursday 13 August 2026: the Friday and Saturday are off, so the Sunday and
        // Monday are the two working days. A Saturday-Sunday office would say Monday.
        expect($dubai->addWorkingDays(new DateTimeImmutable('2026-08-13'), 2)->toDateString())
            ->toBe('2026-08-17')
            ->and($dubai->isWorkingDay(new DateTimeImmutable('2026-08-16')))->toBeTrue()
            ->and($dubai->isWorkingDay(new DateTimeImmutable('2026-08-14')))->toBeFalse();
    });
});

it('says when an office has no holidays recorded at all', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        expect($shimla->hasNoHolidaysRecorded())->toBeTrue();

        OfficeHoliday::factory()->at($shimla)->on('2026-10-20', 'Diwali')->create();

        // Read again the way the next page load does. An office keeps the holidays it has
        // already read, which is what stops module 02's to-do list paying per case.
        expect($shimla->fresh()->hasNoHolidaysRecorded())->toBeFalse();
    });
});

it('refuses the same holiday date twice for one office', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        OfficeHoliday::factory()->at($shimla)->on('2026-10-20', 'Diwali')->create();

        expect(fn () => OfficeHoliday::factory()->at($shimla)->on('2026-10-20', 'Deepavali')->create())
            ->toThrow(QueryException::class, 'office_holidays_tenant_id_office_id_date_unique');
    });
});

it('lets two offices of one client keep the same holiday date', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $bengaluru = Office::factory()->named('Bengaluru')->create();

        OfficeHoliday::factory()->at($shimla)->on('2026-10-20', 'Diwali')->create();
        OfficeHoliday::factory()->at($bengaluru)->on('2026-10-20', 'Diwali')->create();

        expect(OfficeHoliday::query()->count())->toBe(2);
    });
});

it('refuses a holiday with a blank or padded name', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        expect(fn () => OfficeHoliday::factory()->at($shimla)->on('2026-10-20', ' Diwali')->create())
            ->toThrow(QueryException::class, 'office_holidays_name_not_blank_or_padded');
    });
});

it('refuses a weekly off list that is not a set of weekday numbers', function () {
    TenantContext::run($this->meridian, function () {
        expect(fn () => Office::factory()->weekendOn([0, 7])->create())
            ->toThrow(QueryException::class, 'offices_weekly_off_days_are_weekdays');
    });
});

it('refuses an office that works no day of the week', function () {
    TenantContext::run($this->meridian, function () {
        // Without this the counting below would run forever looking for a working day.
        expect(fn () => Office::factory()->weekendOn([0, 1, 2, 3, 4, 5, 6])->create())
            ->toThrow(QueryException::class, 'offices_weekly_off_days_are_weekdays');
    });
});

it('keeps one client company\'s holidays invisible to another, including through a raw query', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-10-20', 'Diwali')->create();
    });

    TenantContext::run($this->vertex, function () {
        expect(OfficeHoliday::query()->count())->toBe(0)
            ->and(DB::table('office_holidays')->count())->toBe(0);
    });
});

it('refuses a holiday pointing at another client company\'s office', function () {
    $shimla = TenantContext::run($this->meridian, fn () => Office::factory()->named('Shimla')->create());

    TenantContext::run($this->vertex, function () use ($shimla) {
        expect(fn () => OfficeHoliday::query()->create([
            'office_id' => $shimla->getKey(),
            'date' => '2026-10-20',
            'name' => 'Diwali',
        ]))->toThrow(QueryException::class);
    });
});

it('refuses deleting an office that has holidays recorded against it', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-10-20', 'Diwali')->create();

        expect(fn () => $shimla->delete())
            ->toThrow(ReferenceListRefused::class, 'only switched off');

        expect(Office::query()->count())->toBe(1);
    });
});

it('lets a client take a holiday back out, because nothing froze a copy of it', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        $wrongDate = OfficeHoliday::factory()->at($shimla)->on('2026-10-21', 'Diwali')->create();

        $wrongDate->delete();

        expect($shimla->hasNoHolidaysRecorded())->toBeTrue()
            ->and($shimla->addWorkingDays(new DateTimeImmutable('2026-10-20'), 2)->toDateString())
            ->toBe('2026-10-22');
    });
});

it('answers without going back to the database when the office was loaded with its holidays', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();
        OfficeHoliday::factory()->at($shimla)->on('2026-08-17', 'Independence Day observed')->create();

        // The shape module 02's to-do list reader has to use: load the offices and their
        // holidays once, then ask about every open case without paying per ask.
        $loaded = Office::query()->with('holidays')->get();

        DB::enableQueryLog();

        foreach ($loaded as $office) {
            $office->addWorkingDays(new DateTimeImmutable('2026-08-14'), 2);
            $office->isWorkingDay(new DateTimeImmutable('2026-08-17'));
            $office->hasNoHolidaysRecorded();
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toBeEmpty()
            ->and($loaded->first()->addWorkingDays(new DateTimeImmutable('2026-08-14'), 2)->toDateString())
            ->toBe('2026-08-19');
    });
});

it('refuses to count fewer than one working day, rather than handing back a closed day', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        // Saturday 15 August 2026. Asking for none of something used to give this back,
        // which the same office calls a non-working day.
        expect(fn () => $shimla->addWorkingDays(new DateTimeImmutable('2026-08-15'), 0))
            ->toThrow(InvalidArgumentException::class, 'at least one working day');
    });
});

it('hands back a plain date, so a deadline cannot slip a day on the clock it is stored against', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        // Late on Friday evening in India. Read against any other clock this is already
        // Saturday, and the deadline it produced landed a day early once stored.
        $lateFriday = new DateTimeImmutable('2026-08-14 23:30:00', new DateTimeZone('Asia/Kolkata'));
        $deadline = $shimla->addWorkingDays($lateFriday, 2);

        expect($deadline->toDateString())->toBe('2026-08-18')
            ->and($deadline->utc()->toDateString())->toBe('2026-08-18')
            ->and($deadline->format('H:i:s'))->toBe('00:00:00');
    });
});

it('walks past a run of holidays rather than stopping at the first working day it wants', function () {
    TenantContext::run($this->meridian, function () {
        $shimla = Office::factory()->named('Shimla')->create();

        // Monday to Wednesday closed: the two working days are the Thursday and Friday.
        foreach (['2026-08-17', '2026-08-18', '2026-08-19'] as $date) {
            OfficeHoliday::factory()->at($shimla)->on($date, 'Local festival')->create();
        }

        expect($shimla->addWorkingDays(new DateTimeImmutable('2026-08-14'), 2)->toDateString())
            ->toBe('2026-08-21');
    });
});
