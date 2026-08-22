<?php

namespace App\Exceptions;

use App\Models\EmployeeStatutoryId;
use RuntimeException;

/**
 * The refusals the employee record makes for itself, rather than leaving to a screen
 * that may not exist yet: a reporting line that loops, an identifier of a kind this
 * product does not recognise, and an Aadhaar number written into a field that was
 * never meant to hold one.
 *
 * They live on the record because a form is not the only way a row arrives — an
 * import, a queued job and a seeded process all write here too, and each of them
 * would otherwise need to remember the same rule.
 */
class EmployeeRecordRefused extends RuntimeException
{
    public static function selfManaged(int $userId): self
    {
        return new self("Person [{$userId}] cannot be set to report to themselves.");
    }

    public static function reportingLineLoop(int $userId, int $managerId): self
    {
        return new self(
            "Person [{$userId}] cannot be set to report to person [{$managerId}], who already reports up to them."
        );
    }

    /**
     * A job row a case is pinned to cannot be withdrawn. Withdrawing hides the row, and
     * a case pointing at a hidden row renders no department, no designation and no
     * manager — which is the whole thing the case pinned it for.
     *
     * @param  list<int>  $caseIds
     */
    public static function pinnedByCase(int $recordId, array $caseIds): self
    {
        $cases = implode(', ', $caseIds);

        return new self(
            "Job row [{$recordId}] cannot be withdrawn: case [{$cases}] reads this person's department, "
            .'designation and manager through it. Correct the job history with a new row instead.'
        );
    }

    public static function unknownStatutoryType(string $type): self
    {
        $known = implode(', ', EmployeeStatutoryId::Types);

        return new self("[{$type}] is not an identifier this product holds. Known kinds: {$known}.");
    }

    /**
     * The refusal that keeps "we do not hold your employees' Aadhaar numbers" true.
     * What is stored instead is a verification timestamp, the four digits the masked
     * form itself shows, and the consent that was taken — all in one table.
     */
    public static function aadhaarNumber(string $type): self
    {
        return new self(
            'That value is an Aadhaar number, and this product never stores one. It was rejected from the '
            ."[{$type}] identifier. Record the verification and its consent instead."
        );
    }
}
