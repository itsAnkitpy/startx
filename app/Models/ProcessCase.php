<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\ProcessCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'template_id', 'subject_user_id', 'subject_employment_record_id', 'initiated_by',
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

    /** What has been done on this case. A step nobody has touched has no row here. */
    public function steps(): HasMany
    {
        return $this->hasMany(CaseStep::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id');
    }
}
