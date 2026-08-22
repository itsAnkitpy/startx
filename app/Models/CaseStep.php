<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\CaseStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody did at one step of one case.
 *
 * A row appears the moment somebody first touches the step — including picking it up
 * from a shared queue — and never before. A step nobody has started therefore has no row
 * anywhere, which is deliberate: whether a step is available is worked out from what has
 * already closed, so there is no row for two people to create at once and no case that
 * stalls because a row nobody wrote was never waited on.
 *
 * It holds no copy of what the step said. The definition is read through the frozen
 * version the case points at, which cannot change underneath it.
 */
#[Fillable([
    'case_id', 'sequence', 'assignee_id', 'external_assignee', 'acted_at', 'outcome', 'payload',
])]
class CaseStep extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CaseStepFactory> */
    use HasFactory;

    /**
     * The outcomes that let the next group start. The two hold resolutions pass because
     * a held step must stop blocking the case — an exit frozen behind one department is
     * a statutory breach, and the law gives no pause for it.
     *
     * `held` is not here: a held step is still open. `rejected` and `sent_back` are not
     * here either, and are not closed in this sense at all.
     */
    public const PassingOutcomes = ['approved', 'closed_disputed', 'force_closed'];

    /**
     * How a hold ends. Neither is ever offered as a button and neither may appear in a
     * step's `allowed_outcomes`: both are produced by the two hold-resolution paths.
     *
     * They exist rather than reusing `approved` because a record saying Finance approved
     * a clearance Finance refused is worse than no record at all — the money is still
     * being argued about and the dispute has vanished from the history.
     */
    public const HoldResolutions = ['closed_disputed', 'force_closed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'external_assignee' => 'array',
            'acted_at' => 'datetime',
            'superseded_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessCase::class, 'case_id');
    }

    /** Null on an external step, where the holder has no account. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
