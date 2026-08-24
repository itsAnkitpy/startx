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

    /*
    | Reading a process out of a flat file
    */

    /**
     * The file is not a process, with every bad row named at once and nothing written.
     *
     * Nothing at all rather than the good rows, which is the same sentence as
     * {@see cannotPublish} arriving one step earlier. A list of customers missing one
     * customer is a shorter list; a process missing one step is an exit that reaches the
     * end with a department never having been asked, and nothing about it looks wrong.
     * The reasoning is recorded in full in module 02's plan.
     *
     * @param  list<string>  $problems
     */
    public static function cannotImport(string $path, array $problems): self
    {
        $listed = implode("\n  - ", $problems);

        return new self(
            "Nothing was imported from [{$path}]:\n  - {$listed}"
        );
    }

    /**
     * One row that cannot be turned into a step, in the words of the person who typed
     * it. Always caught and reported with its line number beside it, never on its own.
     */
    public static function thatRowIsWrong(string $why): self
    {
        return new self($why);
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

    /*
    | Running a case
    */

    /** A draft has not been checked yet and a retired version has cases reading it. */
    public static function onlyALiveProcessRuns(string $name, int $version, string $status): self
    {
        return new self(
            "[{$name}] version {$version} is {$status}, and a case can only be opened on a live process."
        );
    }

    /**
     * An exit is about somebody, and the whole audit claim rests on the case being pinned
     * to their job row as it stood at that moment.
     */
    public static function thisProcessNeedsAPerson(string $name): self
    {
        return new self("[{$name}] is about an employee, so a case cannot be opened without one.");
    }

    public static function thePersonHasNoCurrentJobRow(int $userId, string $name): self
    {
        return new self(
            "Person {$userId} has no current job row, so a case on [{$name}] has nothing to record "
            .'their department, designation and manager as they stand today.'
        );
    }

    public static function thisProcessIsNotAboutAPerson(string $name, string $subjectKind): self
    {
        return new self("[{$name}] is about {$subjectKind}, so a case on it cannot name a person.");
    }

    /**
     * A legal deadline is counted in working days against the office the person worked
     * in, so a process about anything other than a person has no calendar to count
     * against. Refused rather than stored with no deadlines beside it, which would put a
     * date on the case that nothing was ever worked out from.
     */
    public static function thisProcessHasNoLegalClock(string $name, string $subjectKind): self
    {
        return new self(
            "[{$name}] is about {$subjectKind} rather than a person, so it has no office "
            .'calendar and cannot carry a legal deadline.'
        );
    }

    /**
     * No office on the job row means no working-day calendar, and a settlement deadline
     * counted against no calendar is a wrong legal date rather than a missing one.
     */
    public static function theirJobRowNamesNoOffice(int $userId, string $name): self
    {
        return new self(
            "Person {$userId} has no office on their current job row, so a case on [{$name}] "
            .'has no working-day calendar to count their legal deadline against.'
        );
    }

    /**
     * Whose turn it is, refused in one sentence. A step in a group nothing has reached, a
     * step that has already closed, and a step that this case skipped are the same answer
     * from where the person acting is standing.
     */
    public static function itIsNotThatStepsTurn(int $sequence): self
    {
        return new self("Step {$sequence} is not open on this case.");
    }

    public static function somebodyElseHasThatStep(string $step): self
    {
        return new self("[{$step}] has already been picked up by somebody else on this case.");
    }

    /**
     * @param  array<mixed>  $allowed
     */
    public static function outcomeNotOffered(string $step, string $outcome, array $allowed): self
    {
        return new self(
            "[{$step}] does not offer [{$outcome}]. It offers: ".implode(', ', $allowed).'.'
        );
    }

    /**
     * The two hold resolutions are produced by the two ways a hold ends, never picked
     * from a form — a record saying Finance approved a clearance Finance refused is worse
     * than no record at all.
     */
    public static function notSomethingAnyoneChooses(string $outcome): self
    {
        return new self(
            "[{$outcome}] is not something anyone chooses at a step. It is what resolving a hold records."
        );
    }

    public static function notAWayOutOfAHold(string $outcome): self
    {
        return new self("[{$outcome}] is not one of the two ways a hold ends.");
    }

    public static function thatStepIsNotOnHold(string $step, string $outcome): self
    {
        return new self("[{$step}] is not on hold, so it cannot be recorded as [{$outcome}].");
    }

    /**
     * A hold, a hold's ending and a cancellation all have to say why. Each of the three
     * is somebody overriding what the process would otherwise have done, and an override
     * with no reason on it is the skippable decision this rebuild exists to remove.
     */
    public static function needsAReason(string $act): self
    {
        return new self("{$act} has to say why.");
    }

    public static function aSendBackNeedsAStepToGoTo(string $step): self
    {
        return new self("Sending the case back from [{$step}] has to name the step it goes back to.");
    }

    public static function thisProcessHasNoSuchStep(int $sequence): self
    {
        return new self("This process has no step {$sequence}.");
    }

    /**
     * Steps in one group run at the same time, so neither can be the reason the other was
     * wrong, and a later step cannot be sent back to at all.
     */
    public static function aSendBackOnlyGoesBackwards(string $from, string $target): self
    {
        return new self(
            "[{$from}] cannot send the case back to [{$target}], which does not run before it."
        );
    }

    /**
     * A cancellation is read years later, so it is stored rather than truncated — and
     * refused in a sentence rather than left to the database to answer with an error.
     */
    public static function thatReasonIsTooLong(int $limit): self
    {
        return new self("A reason can be at most {$limit} characters long.");
    }

    /**
     * A step this case skipped never ran, so sending the case back to it would reopen
     * nothing while the history claimed otherwise.
     */
    public static function thisCaseSkippedThatStep(string $from, string $target): self
    {
        return new self(
            "[{$from}] cannot send the case back to [{$target}], which this case never needed."
        );
    }

    public static function thisCaseHasAlreadyEnded(string $state): self
    {
        return new self("This case is {$state}, and nothing further can be recorded on it.");
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
