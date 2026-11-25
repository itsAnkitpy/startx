<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One document as it was issued on a case: where its PDF is, and the PDF's SHA-256.
 *
 * The PDF is the record, and the database refuses to change what this row says about it or
 * to remove the row. A wording edited next year never touches a letter already given,
 * because nothing draws an issued letter again.
 *
 * No factory: the only way a row should come to exist is a letter being drawn and stored.
 */
#[Fillable(['case_id', 'kind', 'disk', 'path', 'sha256', 'issued_by'])]
class IssuedDocument extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const RelievingLetter = 'relieving_letter';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
