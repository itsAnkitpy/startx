<?php

namespace App\Models;

use App\Exceptions\ReferenceListRefused;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OfficeFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One place a client company works from — a name, a country, the state as an ISO 3166-2
 * code, and one free-text block for the rare letter that prints an address.
 *
 * The state is the only piece of geography this product needs, because professional tax
 * in India follows where a person works rather than where the company is registered, so a
 * leaver's settlement handed to module 11's payroll adapter has to be able to name it.
 * Everything else about an address only ever gets printed, and printing does not need it
 * split into columns that half the world does not have.
 *
 * An office also owns the working-day calendar the whole product's central deadline is
 * counted against — which weekdays it does not work, and which dates it is closed. That
 * lives here rather than on the client company because Indian public holidays are set by
 * state and follow where a person actually works, so a client with an office in Shimla
 * and one in Bengaluru has two calendars and a leaver's deadline is counted against the
 * one they worked in.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped from
 * the client company in scope.
 */
#[Fillable(['name', 'country', 'state_code', 'address_block', 'weekly_off_days', 'active'])]
class Office extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'weekly_off_days' => 'array',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(OfficeHoliday::class);
    }

    /**
     * Whether this office works on a given date.
     *
     * A whole day either counts or it does not — there are no opening and closing times
     * anywhere in the product. That is exact for the statutory deadline, which the law
     * counts in whole working days and which is the only deadline here with a legal
     * consequence, and approximate for a step's own internal target, which has none.
     *
     * Only the calendar date is read; see {@see self::calendarDateOf()} for why the time
     * of day is thrown away rather than converted.
     *
     * ponytail: whole days, no office hours. Add opening and closing times to this table
     * the day a client says the approximation costs them something.
     */
    public function isWorkingDay(DateTimeInterface $date): bool
    {
        return $this->isWorkingDate($this->calendarDateOf($date), $this->closedDates());
    }

    /**
     * The date that is a given number of this office's working days after another date.
     *
     * Counting starts the day *after* the one handed in, which is what the statute means:
     * two working days after a Friday last working day is the Tuesday, and the Wednesday
     * when that Monday is a recorded holiday.
     *
     * The loop always ends. The database refuses an office with no working weekday at
     * all, and a holiday list is finite, so working days resume after the last one.
     *
     * Fewer than one day is refused rather than answered. Asking for none of something
     * has no meaning here, and the old answer was the date handed in — which is a
     * Saturday when a Saturday was handed in, so a caller with an off-by-one got a
     * closed day back and a wrong legal date on somebody's screen.
     *
     * @throws InvalidArgumentException when fewer than one working day is asked for
     */
    public function addWorkingDays(DateTimeInterface $from, int $days): CarbonImmutable
    {
        if ($days < 1) {
            throw new InvalidArgumentException(
                "A deadline must be at least one working day away, and [{$days}] was asked for."
            );
        }

        $date = $this->calendarDateOf($from);
        $closed = $this->closedDates();
        $remaining = $days;

        while ($remaining > 0) {
            $date = $date->addDay();

            if ($this->isWorkingDate($date, $closed)) {
                $remaining--;
            }
        }

        return $date;
    }

    /**
     * The moment that is a given number of this office's working hours after another
     * moment.
     *
     * A step's own service target is set in hours rather than in days, because a
     * clearance due in four hours is an ordinary thing to promise and a clearance due in
     * one working day is not the same promise. But the calendar underneath is still whole
     * days: a working day contributes twenty-four hours here and a closed day contributes
     * none. So four hours from Friday at nine in the evening has three hours left when
     * the weekend stops the clock, and finishes at one o'clock on Monday morning.
     *
     * Unlike {@see self::addWorkingDays()} the time of day is kept, because the whole
     * point of an hourly target is that it lands part-way through a day. Part of an hour
     * is accepted for the same reason: a reminder halfway through a five-hour target is
     * two and a half working hours in.
     *
     * ponytail: a working day is twenty-four hours, not the hours an office is open. The
     * approximation is the same one {@see self::isWorkingDay()} already makes and it errs
     * towards giving the holder more time. Add opening and closing hours here and there
     * together, the day a client says it costs them something.
     *
     * @throws InvalidArgumentException when no time at all is asked for
     */
    public function addWorkingHours(DateTimeInterface $from, int|float $hours): CarbonImmutable
    {
        if ($hours <= 0) {
            throw new InvalidArgumentException(
                "A target must be some working time away, and [{$hours}] was asked for."
            );
        }

        $closed = $this->closedDates();
        $at = CarbonImmutable::instance($from);
        $remaining = (int) round($hours * 3600);

        // Ends for the same reason `addWorkingDays` does: the database refuses an office
        // with no working weekday, and a holiday list is finite.
        while (true) {
            $nextMidnight = $at->startOfDay()->addDay();

            if ($this->isWorkingDate($at, $closed)) {
                $secondsLeftToday = $nextMidnight->getTimestamp() - $at->getTimestamp();

                if ($remaining <= $secondsLeftToday) {
                    return $at->addSeconds($remaining);
                }

                $remaining -= $secondsLeftToday;
            }

            $at = $nextMidnight;
        }
    }

    /**
     * Whether this office has no holidays recorded at all.
     *
     * A deadline counted against an empty calendar gets weekends off and nothing else,
     * which is a defensible default and very likely wrong for India. Whoever shows such
     * a deadline has to say so on the same screen, because a system quietly computing a
     * legal date from a calendar nobody filled in is the exact failure this product is
     * sold against.
     *
     * Read through the relation for the reason given on {@see self::closedDates()}, so an
     * office asked this after a holiday was added under it answers from what it already
     * read. Ask a freshly read office where that matters.
     */
    public function hasNoHolidaysRecorded(): bool
    {
        return $this->holidays->isEmpty();
    }

    /**
     * Every date this office is closed, keyed for lookup.
     *
     * Read through the relation, not a fresh query, so an office loaded with its holidays
     * already attached costs nothing to ask again. That is what module 02 needs: Anjali's
     * to-do list works out for five hundred open cases whether each step is late, and
     * every one of those asks reaches this method. A query here would be a query per ask,
     * which cannot meet that module's rule of a fixed number of queries however many
     * cases there are — measured 21 August 2026, five offices loaded together with their
     * holidays still cost five queries before this changed.
     *
     * The price is that a holiday added part-way through a request is not seen by a
     * calculation later in the same request. Nothing does that, and the escape is
     * `$office->unsetRelation('holidays')`.
     *
     * No upper date bound either. A client's list is a few dozen rows, and the bound was
     * saving nothing while making an already-loaded list unusable.
     *
     * @return array<string, true>
     */
    private function closedDates(): array
    {
        return $this->holidays
            ->mapWithKeys(fn (OfficeHoliday $closed) => [$closed->date->toDateString() => true])
            ->all();
    }

    /**
     * The calendar date a caller means, with the time of day thrown away.
     *
     * A moment in time is two different dates depending on the clock it is read against:
     * the instant "13 August, 8pm in London" is "14 August, 1.30am in India", and counting
     * two working days from those gives the 17th and the 18th. Rather than guess which
     * clock a caller meant, this reads the date exactly as they wrote it and drops the
     * rest, so the answer coming back is a plain date at midnight and never carries a
     * foreign clock into a column somebody stores it in.
     */
    private function calendarDateOf(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'));
    }

    /**
     * @param  array<string, true>  $closed
     */
    private function isWorkingDate(CarbonImmutable $date, array $closed): bool
    {
        $weeklyOff = array_map('intval', (array) $this->weekly_off_days);

        return ! in_array($date->dayOfWeek, $weeklyOff, true)
            && ! isset($closed[$date->toDateString()]);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $office): void {
            throw ReferenceListRefused::deletion($office);
        });
    }
}
