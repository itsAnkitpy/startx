<?php

namespace App\Models;

use App\Exceptions\EmployeeRecordRefused;
use App\Tenancy\BelongsToTenant;
use Database\Factories\EmploymentRecordFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Priya's job, as dated rows. A promotion, a transfer, a change of manager or a change
 * of status inserts a row; nothing is ever updated in place. Exactly one row per person
 * has no end date, which the database enforces rather than the application.
 *
 * The reason this is history rather than four columns on the account: this product is
 * sold on answering who approved an exit, when, and what they were looking at. A case
 * read in a tribunal next year has to show the department, the manager and the status
 * the person actually had at the time, not the ones they have now.
 *
 * Designation, work location and cost centre join these rows in step 5, with the lists
 * they point at.
 *
 * Two fields are deliberately absent from what a form may fill, for the same reason:
 * `tenant_id` is stamped from the client company in scope, and `recorded_by` from
 * whoever is signed in. Both answer "where did this row come from", and a submitted
 * field that answers that can lie about it. The dates and the department are the
 * opposite — somebody types those, and the screen has to check the department is one
 * they are allowed to write to.
 */
#[Fillable([
    'user_id', 'employee_code', 'org_unit_id', 'employment_type', 'employment_status',
    'reports_to_id', 'joining_date', 'last_working_day', 'effective_from', 'effective_to',
    'change_reason',
])]
class EmploymentRecord extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EmploymentRecordFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * A row entered by mistake is withdrawn, and the framework's own soft-delete trait
     * reads this constant to find the column — so the schema says withdrawn rather than
     * deleted, and every ordinary query still hides withdrawn rows without anyone
     * having to remember a filter.
     */
    public const DELETED_AT = 'withdrawn_at';

    /**
     * How far up a reporting line the loop check will walk. A chain is a handful of
     * people deep in practice; this is a stop so that a loop written straight into the
     * database by hand cannot make the check run forever.
     */
    private const MAX_REPORTING_DEPTH = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'last_working_day' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            $record->refuseReportingLoop();
        });

        // Who entered the row, stamped rather than submitted. Null where nobody is
        // signed in, which is what an import or a scheduled pass looks like.
        static::creating(function (self $record): void {
            $record->recorded_by ??= Auth::id();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The part of the structure this job sits in. What every permission question about
     * this person is narrowed by.
     */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /**
     * The row that was true on a given date. The end date is the last day the row was
     * true, so a transfer on 1 April ends the previous row on 31 March and the two
     * never overlap.
     */
    public function scopeAsOf(Builder $query, DateTimeInterface|string $date): void
    {
        $on = Carbon::parse($date)->toDateString();

        $query->where('effective_from', '<=', $on)
            ->where(function (Builder $query) use ($on): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $on);
            });
    }

    /**
     * Withdraw a row entered by mistake, which is not the same act as a job change: a
     * job change is a new row, a withdrawal says this row never should have existed.
     *
     * The end date passes to the row before it — the live row with the greatest earlier
     * start date. One rule covers both cases: withdrawing the current row makes the
     * previous row current again, and withdrawing a middle row extends its predecessor
     * over the gap it leaves.
     *
     * The order inside the transaction matters. The row is withdrawn before its
     * predecessor is reopened, because the index allowing one open row per person only
     * ignores this row once it is withdrawn.
     */
    public function withdraw(User $by, string $reason): void
    {
        DB::transaction(function () use ($by, $reason): void {
            $predecessor = $this->predecessor();

            $this->withdrawn_by = $by->getKey();
            $this->withdrawn_reason = $reason;
            $this->save();

            $this->delete();

            $predecessor?->update(['effective_to' => $this->effective_to]);
        });
    }

    /**
     * The live row this one continued from — the row that ends the day before this one
     * starts.
     *
     * Not simply the nearest earlier row, which is what the plan first said and what
     * this originally did. A rehire entered by mistake has a gap in front of it, because
     * the person really did leave: passing the withdrawn row's end date back across that
     * gap would make a leaver currently employed again, with their own last working day
     * still sitting on the row. When there is a gap there is no row to pass anything to,
     * and the person stays a leaver.
     */
    private function predecessor(): ?self
    {
        $dayBefore = $this->effective_from->copy()->subDay()->toDateString();

        return self::query()
            ->where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->where('effective_to', $dayBefore)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Refuse a reporting line that comes back round to the person it starts from,
     * walking up from the proposed manager.
     *
     * Each step reads that person's most recent job row, not only the row that is true
     * today. Reading only current rows stopped the walk dead at anybody who had left:
     * with Deepak gone and his team not yet moved to his successor, a circle could be
     * drawn straight through him from an ordinary screen, and it became a live loop the
     * day he was rehired.
     *
     * The database refuses the one-step version of this on its own. The stop after
     * fifty steps is not a correctness claim — a loop written straight into the
     * database by hand is still not covered, which is the same limit the structure tree
     * has.
     */
    private function refuseReportingLoop(): void
    {
        $managerId = $this->getAttribute('reports_to_id');

        if ($managerId === null || ! $this->isDirty('reports_to_id')) {
            return;
        }

        $subjectId = (int) $this->getAttribute('user_id');
        $managerId = (int) $managerId;

        if ($managerId === $subjectId) {
            throw EmployeeRecordRefused::selfManaged($subjectId);
        }

        for ($step = 0; $step < self::MAX_REPORTING_DEPTH; $step++) {
            $above = self::query()
                ->where('user_id', $managerId)
                ->orderByDesc('effective_from')
                ->value('reports_to_id');

            if ($above === null) {
                return;
            }

            if ((int) $above === $subjectId) {
                throw EmployeeRecordRefused::reportingLineLoop($subjectId, (int) $this->getAttribute('reports_to_id'));
            }

            $managerId = (int) $above;
        }
    }
}
