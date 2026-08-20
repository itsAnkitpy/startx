<?php

namespace App\Exceptions;

use App\Authorization\Permission;
use RuntimeException;

/**
 * Thrown when something tries to give a role an action that does not exist in code.
 * A permission name only means anything if there is code behind it performing the
 * action, so an invented name is not a new capability — it is a tick-box that
 * silently denies forever, and the client has no way to tell.
 */
class UnknownPermission extends RuntimeException
{
    public static function named(string $permission): self
    {
        $known = implode(', ', Permission::all());

        return new self("[{$permission}] is not an action this system can perform. Known actions: {$known}.");
    }
}
