<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everything this product holds about an employee's Aadhaar, which is deliberately
 * not the number: that the document was seen and by whom, the four digits the masked
 * form itself shows, and the consent that was taken for it.
 *
 * One table and one row per person, so that there is a single place to point at when
 * proving what is kept, a single row to empty when consent is withdrawn, one
 * permission to grant, and one table to keep out of every export.
 *
 * Why the number is refused rather than protected: payroll is the only thing that has
 * a use for it and payroll is out of scope, so holding it buys nothing while making
 * this database the most attractive single target it could be. The largest penalty in
 * India's data-protection law — up to ₹250 crore — attaches to failing to keep
 * personal data secure, so not holding the number lowers both the chance of that
 * failure and its cost. The Aadhaar Act adds its own duties on top, and whether a
 * particular client falls inside that Act's own ecosystem is a lawyer's question, not
 * ours.
 */
#[Fillable([
    'user_id', 'verified_at', 'verified_by', 'last_four',
    'notice_version', 'consented_at', 'consent_channel', 'consent_withdrawn_at',
])]
class AadhaarVerification extends Model
{
    use BelongsToTenant;

    /**
     * The Verhoeff multiplication table. UIDAI computes the twelfth digit of every
     * Aadhaar number with this algorithm, which is why a number can be recognised
     * without holding a list of them.
     */
    private const VERHOEFF_D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /** The Verhoeff permutation table, applied by position from the right. */
    private const VERHOEFF_P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'consent_withdrawn_at' => 'datetime',
        ];
    }

    /**
     * Whether a value is an Aadhaar number, so that anything asked to store one can
     * refuse it. Twelve digits once spacing and hyphens are ignored, a leading digit
     * other than 0 or 1, and the Verhoeff check digit matching.
     *
     * The leading-digit rule came from secondary sources on 20 August 2026 — Aadhaar
     * validator tools and a payments vendor's engineering blog — not from UIDAI's own
     * documentation, so it is a lead rather than evidence. It is used here only to
     * make the check narrower, so being wrong about it would refuse more than it has
     * to, not less.
     *
     * {@see EmployeeStatutoryId} calls this on every identifier it stores. Module 04's
     * per-client profile fields will call it too, which is why it is a plain static
     * question rather than a form rule.
     */
    public static function looksLikeANumber(string $value): bool
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 12 || $digits[0] === '0' || $digits[0] === '1') {
            return false;
        }

        $checksum = 0;

        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $checksum = self::VERHOEFF_D[$checksum][self::VERHOEFF_P[$position % 8][(int) $digit]];
        }

        return $checksum === 0;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Consent withdrawn: the four digits and the verification go, and what stays is
     * the record that consent was given and then withdrawn — which is the evidence the
     * withdrawal itself has to leave behind.
     *
     * Nothing an employee's own exit needs is held here, so emptying this row cannot
     * block their settlement or their letters.
     */
    public function withdrawConsent(): void
    {
        $this->update([
            'last_four' => null,
            'verified_at' => null,
            'verified_by' => null,
            'consent_withdrawn_at' => now(),
        ]);
    }
}
