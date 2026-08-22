<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CaseEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The trail: everything that happened on a case, in the order it happened.
 *
 * Rows are added and never touched again. That is the claim this product is sold on, so
 * it is written at the database as a trigger and refused here as well — the trigger is
 * what actually holds, and this is what stops a mistake reaching the database in the
 * first place and reads as a plain sentence when it does.
 *
 * There is no `updated_at`, because a row that can be updated is not what this table is.
 * An external participant's action is recorded against the address and token in the
 * payload with no actor, so nothing in the history reads as though an employee did it.
 */
#[Fillable(['case_id', 'actor_id', 'type', 'payload'])]
class CaseEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CaseEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw ProcessRefused::historyCannotChange();
        });

        static::deleting(function (): never {
            throw ProcessRefused::historyCannotBeRemoved();
        });
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessCase::class, 'case_id');
    }

    /** Null where nobody was signed in: a scheduled pass, an import, or an external link. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
