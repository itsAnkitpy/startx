<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a change would break something the rest of the system points at by
 * name. Two cases.
 *
 * A role's permanent internal name is the only part of a role that code may refer to
 * — a seeded process template naming the role it routes a step to, and the rule that
 * a client keeps two administrators. Change it and both stop finding anything, with
 * no error at the point of the change.
 *
 * Deleting a seeded role is refused because its grants go with it through a database
 * cascade that no model event sees, which would take every administrator away in one
 * statement and never reach the two-administrator rule.
 */
class SystemRoleProtected extends RuntimeException
{
    public static function keyIsPermanent(string $from, string $to): self
    {
        return new self("A role's internal name cannot change once set (tried to rename [{$from}] to [{$to}]). Rename its label instead — no permission check reads the label.");
    }

    public static function cannotBeDeleted(string $key): self
    {
        return new self("The [{$key}] role is part of how the system works and cannot be deleted. Rename it, or change which actions it may perform.");
    }
}
