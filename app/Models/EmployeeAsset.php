<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A laptop, a phone, an access card: who had it, from when to when, and what state it
 * was in at each end. Dated rows rather than a status, because the disputed-settlement
 * argument this product is sold on is an argument about whether a laptop came back in
 * July, and a status cannot answer that after the fact. BambooHR models it the same
 * way, with a loan date and a return date per row.
 *
 * What this deliberately does not become: an IT asset tool. Those track a longer
 * lifecycle — in transit, wiped, redeployed — and hold photographs as evidence. Ours
 * records what a settlement dispute needs. Photographs wait for module 04's file
 * storage, and a client who wants the full lifecycle should buy an asset tool that we
 * then integrate with.
 */
#[Fillable([
    'user_id', 'asset_type', 'identifier', 'org_unit_id',
    'issued_at', 'issued_by', 'issue_condition_note',
    'returned_at', 'returned_to', 'return_condition_note',
])]
class EmployeeAsset extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The department that handed it over, which is the one that chases it at exit.
     */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function returnedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_to');
    }
}
