<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantScope;
use Database\Factories\ProcessCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One run of one process — Rakesh's exit, Priya's onboarding, a hiring request for a
 * vacancy nobody has filled yet.
 *
 * Called `ProcessCase` and not `Case` only because `case` is a reserved word in PHP; the
 * table is `cases` and the product's word for it is a case.
 *
 * It points at the frozen process version it opened on, at the person it is about, and
 * at the dated job row that was true for them at that moment. That last pointer is what
 * makes the trail honest: a case read a year later renders the department, designation
 * and manager the person had at the time, not the ones they have now.
 */
#[Fillable([
    'number', 'template_id', 'subject_user_id', 'subject_employment_record_id', 'initiated_by',
    'opened_at', 'statutory_from', 'statutory_due_at', 'gratuity_due_at',
    'settings_snapshot', 'subject_facts_snapshot',
])]
class ProcessCase extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProcessCaseFactory> */
    use HasFactory;

    protected $table = 'cases';

    /**
     * How a case ends, and how it is still running. Read from the two timestamps rather
     * than stored, which is the point: the old tool kept a status number beside the
     * facts and the two drifted apart, so an exit could read as finished with three
     * clearances still open. There is no column here to disagree with anything.
     */
    public const Open = 'open';

    public const Closed = 'closed';

    public const Cancelled = 'cancelled';

    /**
     * The questions a condition may ask about the person the case is about, answered
     * once when the case opens and read from `subject_facts_snapshot` for ever after.
     *
     * The list is here rather than with the code that answers it because two places need
     * it: the engine, which answers only the ones a process actually asks, and the check
     * that runs when a process goes live, which refuses a question this list does not
     * have. Without that refusal a client could branch on a grade — which nothing in this
     * product holds — and the step behind it would silently never happen.
     *
     * Each is paired with how it reads in a refusal, so the sentence a client is shown
     * when they ask about something else is written from this list rather than from our
     * column names.
     *
     * `manages_anyone` and `equipment_issued` are the two the exit itself changes while
     * it runs, which is the whole reason these answers are frozen.
     */
    public const SubjectFacts = [
        'org_unit_id' => 'their department',
        'designation_id' => 'their designation',
        'office_id' => 'their office',
        'manages_anyone' => 'whether they manage anybody',
        'equipment_issued' => 'whether they hold any equipment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'statutory_from' => 'immutable_date',
            'statutory_due_at' => 'immutable_date',
            'gratuity_due_at' => 'immutable_date',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'settings_snapshot' => 'array',
            'subject_facts_snapshot' => 'array',
        ];
    }

    /**
     * Number each case from one inside its own client company, as it is saved.
     *
     * **Counted rather than kept in a counter column**, because a counter is a second copy
     * of what the rows already say. The client's own rows are the only ones this read can
     * see, so the highest number here is the highest number they have.
     *
     * **Here rather than in the engine, because it has to happen once per case saved.** A
     * value worked out where a case is built instead is worked out once for a whole batch —
     * a test creating five hundred cases handed all five hundred the same number, which the
     * unique index refused.
     *
     * **The lock is what makes two people raising a request in the same second safe.** It
     * is held on the client's own id until the surrounding transaction ends, so the second
     * one waits rather than reading the same highest number and colliding — and a refused
     * insert in Postgres abandons the whole transaction, which would lose the case rather
     * than merely renumber it. The engine opens every case inside a transaction, which is
     * what gives the lock something to be held for.
     *
     * **Both the lock and the count read the case's own client, not the one in scope.**
     * The audited path that lets a migration reach every client at once switches the
     * narrowing off, so a case opened inside it would have been given the highest number
     * on the platform — the very number this exists to stop a client seeing. Nothing opens
     * a case that way today; asking the row which client it belongs to costs nothing and
     * cannot come apart from it. The company is stamped on by the trait above, whose own
     * hook runs before this one.
     *
     * ponytail: nothing else in the product takes this lock, so nothing else ever waits on
     * it. If a client ever opens cases fast enough for the wait to matter, the answer is a
     * counter row per client rather than a wider lock.
     */
    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            if ($case->number !== null) {
                return;
            }

            $client = (int) $case->tenant_id;

            DB::statement('select pg_advisory_xact_lock(?)', [$client]);

            $case->number = (int) static::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $client)
                ->max('number') + 1;
        });
    }

    /**
     * Derived, never assigned. A withdrawn resignation is the case that shows why: nobody
     * approved and nobody rejected, the person simply stopped, so there is no step
     * outcome to read a status off — but there is a cancellation timestamp, and that is
     * the answer.
     *
     * There is no setter and there must never be one.
     */
    protected function state(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->cancelled_at !== null => self::Cancelled,
            $this->closed_at !== null => self::Closed,
            default => self::Open,
        });
    }

    /** The frozen version this case runs on, and reads its steps from. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'template_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * The dated job row that was true for the subject when the case opened. Everything
     * that has to answer "as of when" reads through this — the case trail, the settlement
     * statement, and any letter naming a designation or a manager.
     */
    public function subjectEmploymentRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentRecord::class, 'subject_employment_record_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The job rows this case caused — the reporting lines a succession moved when the
     * person this exit is about left. Empty on every case that moved nobody.
     */
    public function reportingLinesMoved(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class, 'caused_by_case_id');
    }

    /**
     * Everything the case's own forms have collected so far, later answers winning.
     *
     * These are the one thing that is meant to decide anything while a case runs, because
     * they arrive during it — everything else a condition or a rule can ask about was
     * frozen when the case opened. Held steps are in here too: the relation keeps every
     * attempt a send-back has not replaced, so step one's answers are still readable when
     * step two is being worked out.
     *
     * On the case rather than on whichever class wanted it first, because two now do —
     * the engine deciding which steps a case still wants, and the rule that sends a
     * hiring approval to the department the request itself named.
     *
     * @return array<string, mixed>
     */
    public function answersSoFar(): array
    {
        return $this->liveSteps->sortBy('sequence')->pluck('payload')->collapse()->all();
    }

    /** What has been done on this case. A step nobody has touched has no row here. */
    public function steps(): HasMany
    {
        return $this->hasMany(CaseStep::class, 'case_id');
    }

    /**
     * The attempt that counts at each step — one per step at most, with any attempt a
     * send-back replaced left behind.
     *
     * Its own relation rather than a filter on the one above, so a set of cases can be
     * loaded with exactly these rows in one query. That is what lets one person's list
     * be answered across five hundred open cases without a query per case.
     */
    public function liveSteps(): HasMany
    {
        return $this->steps()->whereNull('superseded_at');
    }

    /**
     * What happened on this case, oldest first.
     *
     * Ordered here rather than by whoever reads it, because every reader wants the same
     * thing from it: the last line about a step is the one that stands. A step held and
     * then answered has two, and a screen that took the wrong one would put the words
     * somebody typed to hold a clearance against the day they cleared it.
     */
    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id')->orderBy('id');
    }
}
