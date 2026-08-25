<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\ProcessStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of one version of a process.
 *
 * The whole shape of a process is here and it is one sentence long: a numbered list of
 * steps, where steps sharing a group number happen at the same time. There is no graph,
 * no edge table and no diagram to draw — Workday's business process framework orders
 * steps the same way and puts a condition on each step rather than a branch between
 * them.
 *
 * A minimal step is a name and who it belongs to. Every other field here has a safe
 * default, because the number of decisions a client has to make before their process
 * runs is itself what decides whether they adopt it.
 */
#[Fillable([
    'template_id', 'sequence', 'group_no', 'name', 'participant_kind', 'assignee_rule',
    'open_conditions', 'allowed_outcomes', 'sla_hours', 'reminder_rule', 'escalate_to',
    'on_open', 'on_complete',
])]
class ProcessStep extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProcessStepFactory> */
    use HasFactory;

    /**
     * Internal is an account holder, resolved by module 03. External is somebody with no
     * account acting through a signed link — a candidate filling in their own details.
     * Gating, deadlines, chasing and the trail are identical for both; only who may act
     * and where the address comes from differ.
     */
    public const ParticipantKinds = ['internal', 'external'];

    /**
     * What a step may offer its actor, and the list a step's `allowed_outcomes` is a
     * subset of. A clearance step offers approve and hold only, because a department
     * cannot reject a resignation it has no authority to stop, and a button that should
     * not exist gets pressed eventually.
     *
     * The two hold resolutions — `closed_disputed` and `force_closed` — are deliberately
     * not here. They are produced by the two ways a hold ends, never chosen from a form.
     */
    public const ChoosableOutcomes = ['approved', 'rejected', 'held', 'sent_back'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'group_no' => 'integer',
            'sla_hours' => 'integer',
            'assignee_rule' => 'array',
            'open_conditions' => 'array',
            'allowed_outcomes' => 'array',
            'reminder_rule' => 'array',
            'escalate_to' => 'array',
            'on_open' => 'array',
            'on_complete' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'template_id');
    }
}
