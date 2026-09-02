<?php

namespace App\Exceptions;

use App\Authorization\AdministratorFloor;
use RuntimeException;

/**
 * Thrown when a change would leave a client company with fewer administrators than
 * the floor. We refuse to build a platform path that rescues a locked-out client, so
 * the lockout has to be prevented instead. Products in this position enforce a
 * minimum — Qlik refuses to remove the last tenant administrator — and security
 * guidance for emergency accounts recommends two with independent credentials.
 */
class TooFewAdministrators extends RuntimeException
{
    public static function onRemoval(int $remaining): self
    {
        return new self(self::sentence($remaining).' Appoint another administrator over the whole company first, then remove this one.');
    }

    public static function onAccountDeletion(int $remaining): self
    {
        return new self(self::sentence($remaining).' Appoint another administrator over the whole company first. Note that an account is normally deactivated rather than deleted, which keeps the person\'s history readable.');
    }

    private static function sentence(int $remaining): string
    {
        $floor = AdministratorFloor::Minimum;

        return "This would leave the company with {$remaining} administrator(s) over the whole of it, and it must keep at least {$floor}.";
    }
}
