<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every refusal the process engine makes, one named constructor each — the same shape
 * module 01 uses for the employee record, the reference lists and the settings store.
 *
 * One class rather than six, because from a caller's point of view these are all the
 * same event: the engine would not accept what it was given, and the message says why
 * in words a client could be shown.
 */
class ProcessRefused extends RuntimeException
{
    /**
     * The process is not fit to go live, with every problem named at once rather than
     * the first one found.
     *
     * All of them together on purpose: Anjali fixing one, republishing, and being told
     * about the next is six round trips through a screen she is using for the first
     * time. The same choice the flat-file importer makes when it reports every rejected
     * row instead of stopping at the first.
     *
     * @param  list<string>  $problems
     */
    public static function cannotPublish(string $name, int $version, array $problems): self
    {
        $listed = implode("\n  - ", $problems);

        return new self(
            "[{$name}] version {$version} cannot go live yet:\n  - {$listed}"
        );
    }

    /**
     * Publishing is what a draft is for. A version that is already live, or that has
     * been retired, has cases reading it and cannot be handed round the cycle again.
     */
    public static function onlyADraftGoesLive(string $name, int $version, string $status): self
    {
        return new self(
            "[{$name}] version {$version} is {$status}, and only a draft can be made live."
        );
    }

    /** A draft is edited in place; there is nothing yet for a new version to protect. */
    public static function aDraftIsEditedInPlace(string $name, int $version): self
    {
        return new self(
            "[{$name}] version {$version} is still a draft, so edit it directly rather than "
            .'starting a new version beside it.'
        );
    }

    /**
     * One unfinished draft at a time, so that two of them cannot be made live in turn
     * with the second quietly undoing the first.
     */
    public static function anUnfinishedDraftAlreadyExists(string $name, int $version): self
    {
        return new self(
            "[{$name}] already has version {$version} as an unfinished draft. Finish or discard "
            .'that one rather than starting another beside it.'
        );
    }

    public static function historyCannotChange(): self
    {
        return new self('A case history entry cannot be changed once it is written.');
    }

    public static function historyCannotBeRemoved(): self
    {
        return new self('A case history entry cannot be removed once it is written.');
    }
}
