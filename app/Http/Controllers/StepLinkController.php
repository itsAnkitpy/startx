<?php

namespace App\Http\Controllers;

use App\Exceptions\ProcessRefused;
use App\Models\CaseStep;
use App\Process\AvailableStep;
use App\Process\AvailableSteps;
use App\Process\CaseEngine;
use App\Process\StepLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The three things somebody with no account can do: look at the step they were sent,
 * answer it, and ask for a new link when the one they have has stopped working.
 *
 * There is no session, no account and no queue behind any of it. The token in the address
 * is the entire permission and every one of the three checks it again on the server —
 * this class holds no rule of its own, it only chooses which page to draw.
 *
 * A refusal that means the link is finished is drawn on the same page a wrong company
 * address is drawn on, in the same words the engine wrote, and carries the one way forward
 * there is: ask for another link, sent to the address already on the record. A refusal
 * over what was typed is a different thing and goes back onto the form, because the link
 * is fine and there is something to correct.
 */
class StepLinkController extends Controller
{
    /** The step, and the answers it allows. Opening it costs one of the link's opens. */
    public function show(string $token, StepLink $links): Response
    {
        $link = $links->find($token);

        if ($link === null) {
            return $this->refused(ProcessRefused::thatLinkNoLongerOpens()->getMessage());
        }

        try {
            $links->refuseUnlessItStillWorks($link);

            $waiting = $this->stepStillWaiting($link);
        } catch (ProcessRefused $refused) {
            return $this->refused($refused->getMessage(), $token);
        }

        $links->opened($link);

        return $this->theStep($token, $waiting);
    }

    /**
     * Record the answer.
     *
     * The outcome is not trusted from the form: the engine checks it against what the step
     * actually offers, and checks the token again before writing anything.
     */
    public function submit(Request $request, string $token, StepLink $links): Response
    {
        $typed = $request->validate([
            'outcome' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            (new CaseEngine)->decideThroughALink(
                $token,
                $typed['outcome'],
                array_filter(['note' => $typed['note'] ?? null]),
            );
        } catch (ProcessRefused $refused) {
            // A refusal over what was typed is not a dead link, and drawing it as one is
            // worse than unhelpful: the only way forward that page offers is a new link,
            // which replaces the answer being given and hands over the same box again, so
            // there is no end to it, and each round spends one of the link's opens. Since
            // the engine started applying the step's own form rules this is the ordinary
            // refusal here rather than a rare one.
            //
            // Whether the link still opens is asked rather than guessed from the message,
            // and it is asked through the same two checks the page itself opens through.
            $answerable = $this->stillAnswerable($links, $token);

            if ($answerable !== null) {
                return $this->theStep($token, $answerable, [
                    'refused' => $refused->getMessage(),
                    'note' => $typed['note'] ?? null,
                ], 422);
            }

            return $this->refused($refused->getMessage(), $token);
        }

        return $this->door(
            'Thank you — your answer is recorded',
            'There is nothing else for you to do. The company can see your answer and will carry on '
                .'from here. This link has now been used and will not open again.',
        );
    }

    /**
     * Send another link for the same step, to the address it was sent to before.
     *
     * The address is never read from this request. Somebody holding a dead link may ask
     * for a live one and that is the whole of it — choosing where the next one goes would
     * turn a finished link into a way of having it delivered somewhere else. For the same
     * reason the page that follows does not name the address back: whoever pressed the
     * button is not necessarily the person it belongs to.
     */
    public function again(string $token, StepLink $links): Response
    {
        $link = $links->find($token);

        if ($link === null) {
            return $this->refused(ProcessRefused::thatLinkNoLongerOpens()->getMessage());
        }

        try {
            $links->issueAgain($link);
        } catch (ProcessRefused $refused) {
            return $this->refused($refused->getMessage());
        }

        return $this->door(
            'A new link is on its way',
            'It has gone to the same address as the last one, and works for the next '
                .StepLink::LastsHours.' hours.',
        );
    }

    /**
     * The page itself, drawn from one place whether it is being opened or drawn again over
     * a refusal. Drawing it again costs none of the link's opens: nobody opened anything,
     * they answered and are being asked to correct it.
     *
     * @param  array<string, mixed>  $also
     */
    private function theStep(string $token, AvailableStep $waiting, array $also = [], int $status = 200): Response
    {
        return response()->view('step-link', [
            'token' => $token,
            'case' => $waiting->case,
            'step' => $waiting->step,
            'lastsHours' => StepLink::LastsHours,
            'opens' => StepLink::Opens,
            ...$also,
        ], $status);
    }

    /**
     * The step this link can still answer, or nothing if the link itself is finished.
     *
     * Both checks, because either can be the reason: the link may have run out of time or
     * opens, or the case may have been answered, cancelled or closed some other way.
     */
    private function stillAnswerable(StepLink $links, string $token): ?AvailableStep
    {
        $link = $links->find($token);

        if ($link === null) {
            return null;
        }

        try {
            $links->refuseUnlessItStillWorks($link);

            return $this->stepStillWaiting($link);
        } catch (ProcessRefused) {
            return null;
        }
    }

    /**
     * The step this link was for, if it is still waiting for an answer.
     *
     * Read through the same reader every other door in the product reads through, so a
     * case that was cancelled or answered by some other route closes this page too rather
     * than showing a form that would be refused on submission.
     */
    private function stepStillWaiting(CaseStep $link): AvailableStep
    {
        return (new AvailableSteps)->for($link->case)->first(
            fn (AvailableStep $waiting) => $waiting->step->sequence === (int) $link->sequence
        ) ?? throw ProcessRefused::thatLinkNoLongerOpens();
    }

    /** A refusal, with the offer of a new link where there is a link to re-issue. */
    private function refused(string $message, ?string $askAgainFor = null): Response
    {
        return $this->door('This link no longer opens', $message, $askAgainFor, 403);
    }

    private function door(string $heading, string $message, ?string $askAgainFor = null, int $status = 200): Response
    {
        return response()->view('tenant-door', array_filter([
            'heading' => $heading,
            'message' => $message,
            'askAgainFor' => $askAgainFor,
        ]), $status);
    }
}
