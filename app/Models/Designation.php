<?php

namespace App\Models;

use App\Exceptions\ReferenceListRefused;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry on a client company's list of designations — "Senior Manager", "Driver".
 *
 * A list rather than free text on the job row, because free text becomes "Sr. Manager",
 * "Senior Manager" and "Sr Manager" inside a year, at which point no report works and a
 * letter prints whichever spelling was typed that day. Module 02 also lets a client write
 * a condition against a designation, which needs a value rather than prose.
 *
 * The name stays editable. What protects the past is that a job row keeps its own copy of
 * the words it read, so no rename reaches backwards into a case somebody has closed.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped from
 * the client company in scope.
 */
#[Fillable(['name', 'active'])]
class Designation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DesignationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $designation): void {
            throw ReferenceListRefused::deletion($designation);
        });
    }
}
