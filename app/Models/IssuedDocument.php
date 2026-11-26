<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document as it was issued on a case: where its PDF is, the PDF's SHA-256, and the
 * recipient's answer once given.
 *
 * The PDF is the record, and the database refuses to change what this row says about it or
 * to remove the row. Only the answer is written later, once. A wording edited next year never touches a letter already given,
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

    /** Each answer the recipient may give, as their button reads and as the answer reads after. */
    public const Answers = [
        'received' => ['Received', 'Received'],
        'accepted' => ['Accept', 'Accepted'],
        'refused' => ['Refuse', 'Refused'],
    ];

    /**
     * Each kind as a person reads it, the question the exit's page asks before showing it
     * (P7), and the answers its recipient may give (P9): a letter opens for whoever may see
     * the person and is only received; the statement opens only for whoever may see the
     * money on it, and is accepted or refused.
     */
    private const Kinds = [
        self::RelievingLetter => ['Relieving letter', 'view', ['received']],
        self::ExperienceLetter => ['Experience letter', 'view', ['received']],
        self::SettlementStatement => ['Settlement statement', 'viewSettlement', ['accepted', 'refused']],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'answered_at' => 'datetime',
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

    /**
     * The answers its recipient may give.
     *
     * @return list<string>
     */
    public function answersOffered(): array
    {
        return self::Kinds[$this->kind][2];
    }

    /** Where it stands, as a person reads it: "Received on 27 November 2026", or nothing yet. */
    public function answerReads(string $timezone): ?string
    {
        return $this->answer === null
            ? null
            : self::Answers[$this->answer][1].' on '.$this->answered_at->timezone($timezone)->format('j F Y');
    }
}
