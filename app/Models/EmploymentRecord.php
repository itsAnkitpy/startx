<?php

namespace App\Models;

use App\Exceptions\EmployeeRecordRefused;
use App\Exceptions\ReferenceListRefused;
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
 * The designation and the office arrived in step 5 with the lists they point at, and each
 * carries a frozen copy of what its row said at the time — see {@see freezeReferenceLabels}.
 * A cost centre was dropped from the plan: nothing in this product reads one.
 *
 * Two fields are deliberately absent from what a form may fill, for the same reason:
 * `tenant_id` is stamped from the client company in scope, and `recorded_by` from
 * whoever is signed in. Both answer "where did this row come from", and a submitted
 * field that answers that can lie about it. The dates and the department are the
 * opposite — somebody types those, and the screen has to check the department is one
 * they are allowed to write to.
 */
#[Fillable([
    'user_id', 'employee_code', 'org_unit_id', 'designation_id', 'office_id',
    'employment_type', 'employment_status', 'reports_to_id', 'joining_date',
    'last_working_day', 'effective_from', 'effective_to', 'change_reason',
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
            $record->freezeReferenceLabels();
        });

        // Module 02's rule, enforced here because the pointer is that module's column
        // and this is the only place a withdrawal happens. Hooked on the delete rather
        // than inside `withdraw` so a bare `delete()` is covered by the same rule.
        static::deleting(function (self $record): void {
            $record->refuseWithdrawalWhileACaseIsPinnedToIt();
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

    /**
     * The entry on the client's designation list this row points at. What it *said* when
     * the row was written is on the row itself, not here.
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * The place the person worked. The country and the state it was in when the row was
     * written are on the row itself, because an edited office entry would otherwise
     * rewrite where every past row claims the person worked — and professional tax
     * follows the state.
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
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
        // Checked here as well as on the delete below, so the refusal lands before this
        // row has been touched. Rolling the transaction back does not undo the two
        // values already assigned in memory, and Eloquent then reads them as saved — so
        // a screen re-rendering from this same record after catching the refusal shows
        // a withdrawal that never happened.
        $this->refuseWithdrawalWhileACaseIsPinnedToIt();

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
     * Refuse to withdraw a job row that a case is pinned to, naming the case.
     *
     * Withdrawing hides the row from every ordinary query, so a case pointing at it
     * would afterwards render no department, no designation and no manager — and the
     * whole reason a case pins a job row is that a tribunal reading it next year sees
     * what was true at the time. A row a case has already been read against is not a row
     * entered by mistake.
     *
     * Deliberately every case and not only an open one, which is what module 02's plan
     * first said: a closed case is the one most likely to be read years later and has the
     * most to lose.
     *
     * ponytail: one unindexed read of the case table per withdrawal. Nothing indexes
     * `cases.subject_employment_record_id`, so this walks every case the client has.
     * Withdrawals are rare and admin-initiated, so it is left alone until step 3 of module
     * 02 adds that table's indexes against a measured number rather than a guess.
     */
    private function refuseWithdrawalWhileACaseIsPinnedToIt(): void
    {
        $caseIds = ProcessCase::query()
            ->where('subject_employment_record_id', $this->getKey())
            ->pluck('id')
            ->all();

        if ($caseIds !== []) {
            throw EmployeeRecordRefused::pinnedByCase((int) $this->getKey(), $caseIds);
        }
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
     * Copy the designation's words, and the office's country and state, onto this row.
     *
     * The whole reason step 5 puts the words here as well as the link: freezing the link
     * freezes *which* list row was chosen, not *what that row said*. A client tidying
     * "Sr. Manager" into "Senior Manager" would otherwise rewrite the designation on every
     * case already closed, and reusing a closed London office entry for a Dublin one would
     * move every past row that named it to Ireland. The state is copied for its own
     * reason: professional tax follows the state a person worked in.
     *
     * Stamped here rather than by whoever writes the row, so a seeder, an import and a
     * screen are all covered by one rule — the same choice already made for who entered
     * the row, and none of the three fields is one a form may fill. Only read when the
     * link itself changes, so an ordinary save costs nothing.
     *
     * A link pointing at another client company's entry, or at nothing at all, is refused
     * here by name. The database would refuse the row anyway, but for the wrong reason —
     * it would report a link with no copy beside it, which sends whoever is debugging an
     * import to the write path instead of to the wrong number in their file.
     *
     * ponytail: one lookup per list per row. A bulk join-in-bulk pass (module 10) writing
     * a thousand rows re-reads the same handful of list rows a thousand times — measured
     * at twenty of the thirty queries ten rows cost. When that pass exists, hand it the
     * rows it already read rather than caching in here.
     */
    private function freezeReferenceLabels(): void
    {
        if ($this->isDirty('designation_id')) {
            $this->recorded_designation_name = $this->designation_id === null
                ? null
                : Designation::query()->whereKey($this->designation_id)->value('name')
                    ?? throw ReferenceListRefused::unknownEntry('designation', (int) $this->designation_id);
        }

        if ($this->isDirty('office_id')) {
            $office = $this->office_id === null
                ? null
                : Office::query()->whereKey($this->office_id)->first(['country', 'state_code'])
                    ?? throw ReferenceListRefused::unknownEntry('office', (int) $this->office_id);

            $this->recorded_office_country = $office?->country;
            $this->recorded_office_state_code = $office?->state_code;
        }
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
