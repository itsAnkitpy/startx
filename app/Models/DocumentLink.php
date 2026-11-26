<?php

namespace App\Models;

use App\Process\DocumentLinks;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link sent to a leaver for the documents issued on her exit: one row per email sent
 * (P13). Only the token's hash is kept, so this row opens nothing if it leaks.
 *
 * No factory: the only way a row should come to exist is a link being sent.
 *
 * @see DocumentLinks for the rules it is opened and answered by
 */
#[Fillable(['case_id', 'token', 'sent_to', 'expires_at'])]
class DocumentLink extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessCase::class, 'case_id');
    }
}
