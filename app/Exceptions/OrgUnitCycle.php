<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a change to a client company's structure would make a unit sit under
 * itself. Every serious product refuses this — Workday's supervisory hierarchy gives
 * each organisation exactly one parent, and Deel allows only linear reporting with no
 * loops — because a loop makes the questions the tree exists to answer, "everything
 * under this branch" and "everything above this unit", run forever.
 */
class OrgUnitCycle extends RuntimeException
{
    public static function underItself(string $name): self
    {
        return new self("[{$name}] cannot be placed under itself.");
    }

    public static function underOwnDescendant(string $name, string $parentName): self
    {
        return new self("[{$name}] cannot be placed under [{$parentName}], which already sits below it.");
    }
}
