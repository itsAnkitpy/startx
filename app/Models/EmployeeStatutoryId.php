<?php

namespace App\Models;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Exceptions\EmployeeRecordRefused;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The identifiers a client has to hand to payroll or print on a letter, and nothing
 * more. Rows typed by kind rather than a column per identifier, following the
 * country-scoped extras in Deel's person object — so a client in a second country is
 * rows rather than a migration.
 *
 * Values are encrypted at rest and hidden from anything that serialises a record, and
 * they carry their own permission separate from ordinary employee data.
 *
 * The price of encrypting them, recorded rather than solved: Laravel scrambles the
 * same input differently every time, which is correct and means two rows holding the
 * same tax number do not look alike. So we cannot ask whether anybody else here has
 * this tax number, which is a fraud signal payroll systems do use. Payroll is out of
 * scope and does its own duplicate checking; the upgrade if a client ever asks is a
 * second column holding a one-way fingerprint that equal values share.
 */
#[Fillable(['user_id', 'type', 'country', 'value', 'verified_at', 'verified_by'])]
#[Hidden(['value'])]
class EmployeeStatutoryId extends Model
{
    use BelongsToTenant;

    /**
     * What a reader without the permission is told, instead of the value. Rippling's
     * shape, and the reason for it is worth keeping: omitting the field silently makes
     * "no tax number on file" and "not yours to see" look identical, which is how a
     * record ends up entered twice.
     */
    public const Withheld = '[withheld]';

    /**
     * The kinds this product holds. A fixed list because each kind means something to
     * a letter or to a payroll handoff, and an invented kind would mean nothing to
     * either. There is deliberately no Aadhaar entry, which is the second belt behind
     * {@see refuseAadhaarNumber}: the number cannot even be stored under an honest
     * label.
     */
    public const Types = [
        'pan',
        'universal_account_number',
        'provident_fund',
        'state_insurance',
        'bank_account',
        'passport',
        'driving_licence',
    ];

    /**
     * The two kinds that are themselves twelve digits long, where a real identifier
     * cannot be told apart from an Aadhaar number by its shape. A provident-fund
     * universal account number is twelve digits, and Indian bank account numbers of
     * twelve digits are ordinary. Roughly one real value in ten would pass the Verhoeff
     * check by chance, so refusing here would reject genuine numbers.
     *
     * Checked on 20 August 2026: the claim that a universal account number always
     * begins with 1, which would have separated the two cleanly, could not be confirmed
     * from any EPFO source, so it is not relied on.
     *
     * This is a stated gap, not an oversight: somebody determined can still hide an
     * Aadhaar number in one of these two fields. What the check does close is the case
     * that actually happens — a number pasted into whichever identifier field was open.
     */
    private const TwelveDigitTypes = ['universal_account_number', 'bank_account'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $identifier): void {
            $identifier->refuseUnknownType();
            $identifier->refuseAadhaarNumber();
        });
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
     * The identifier as a given reader may see it: the value itself, or a marker saying
     * it is on file and withheld.
     *
     * Narrowed to the part of the structure the person belongs to by
     * {@see mayBeReadBy()}, so an HR head responsible for one branch does not read tax
     * numbers across the company — including after that person leaves, which is when
     * their bank account is most in demand.
     */
    public function valueFor(User $reader): string
    {
        return self::mayBeReadBy($reader, $this->user) ? (string) $this->value : self::Withheld;
    }

    /**
     * Whether this reader may read the numbers on one person's file.
     *
     * The one place that question is answered, because three paths ask it — the value on
     * a row, the control that adds one, and the one that removes one — and they have to
     * agree. A reader allowed to see which numbers are on file but not to read them can
     * otherwise delete what they were just told was not theirs to see.
     *
     * **Somebody with no job row yet needs the whole company, not "anywhere at all".**
     * A joiner has no department until their joining is recorded, and the numbers are
     * typed during exactly that window. With no department to narrow to, asking the
     * ordinary question answers yes to anybody holding the action in any branch — so an
     * HR head responsible for one branch could read a joiner destined for another. The
     * honest answer while there is nothing to narrow to is that only a grant covering
     * the whole client company reaches them.
     */
    public static function mayBeReadBy(User $reader, ?User $subject): bool
    {
        $permissions = app(PermissionResolver::class);
        $unit = $subject?->lastKnownOrgUnit();

        return $unit === null
            ? $permissions->reachableUnitIds($reader, Permission::ViewStatutoryId) === null
            : $permissions->allows($reader, Permission::ViewStatutoryId, $unit);
    }

    private function refuseUnknownType(): void
    {
        $type = (string) $this->getAttribute('type');

        if (! in_array($type, self::Types, true)) {
            throw EmployeeRecordRefused::unknownStatutoryType($type);
        }
    }

    /**
     * Refuse an Aadhaar number written into an identifier field. The value read here is
     * the plain one: the encryption cast is applied when the attribute is set, and
     * reading it back decrypts, so this sees what somebody actually typed.
     */
    private function refuseAadhaarNumber(): void
    {
        $type = (string) $this->getAttribute('type');

        if (in_array($type, self::TwelveDigitTypes, true)) {
            return;
        }

        if (AadhaarVerification::looksLikeANumber((string) $this->getAttribute('value'))) {
            throw EmployeeRecordRefused::aadhaarNumber($type);
        }
    }
}
