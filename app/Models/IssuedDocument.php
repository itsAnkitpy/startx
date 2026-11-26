<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public const ExperienceLetter = 'experience_letter';

    /** Drawn by our own page from the exit's settlement lines, never from a client's wording. */
    public const SettlementStatement = 'settlement_statement';

    /**
     * Each kind as a person reads it, and the question the exit's page asks before showing
     * it (P7): a letter opens for whoever may see the person, the statement only for whoever
     * may see the money on it.
     */
    private const Kinds = [
        self::RelievingLetter => ['Relieving letter', 'view'],
        self::ExperienceLetter => ['Experience letter', 'view'],
        self::SettlementStatement => ['Settlement statement', 'viewSettlement'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessCase::class, 'case_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public static function nameOf(string $kind): string
    {
        return self::Kinds[$kind][0];
    }

    public function name(): string
    {
        return self::nameOf($this->kind);
    }

    /** The case policy's question that opens this document. */
    public function openedBy(): string
    {
        return self::Kinds[$this->kind][1];
    }
}
