<?php

namespace App\Models;

use App\Exceptions\ReferenceListRefused;
use App\Tenancy\BelongsToTenant;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One place a client company works from — a name, a country, the state as an ISO 3166-2
 * code, and one free-text block for the rare letter that prints an address.
 *
 * The state is the only piece of geography this product needs, because professional tax
 * in India follows where a person works rather than where the company is registered, so a
 * leaver's settlement handed to module 11's payroll adapter has to be able to name it.
 * Everything else about an address only ever gets printed, and printing does not need it
 * split into columns that half the world does not have.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped from
 * the client company in scope.
 */
#[Fillable(['name', 'country', 'state_code', 'address_block', 'active'])]
class Office extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OfficeFactory> */
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
        static::deleting(function (self $office): void {
            throw ReferenceListRefused::deletion($office);
        });
    }
}
