<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\OfficeHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One date an office is closed, and what it is called.
 *
 * Deliberately deletable, unlike the two client-maintained lists beside it. Nothing
 * freezes a copy of a holiday, so removing one takes nothing out of anybody's history:
 * a case's legal deadline is worked out once when it opens and never recomputed because
 * a list changed underneath it. A client who typed the wrong date has to be able to
 * take it out again.
 *
 * `office_id` may be filled because the composite key and row-level security both
 * refuse another client company's office; `tenant_id` may not, and is stamped from the
 * client company in scope.
 */
#[Fillable(['office_id', 'date', 'name'])]
class OfficeHoliday extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OfficeHolidayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
