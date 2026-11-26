<?php

namespace App\Http\Controllers;

use App\Exceptions\ProcessRefused;
use App\Models\DocumentLink;
use App\Models\IssuedDocument;
use App\Process\DocumentLinks;
use App\Process\StepLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a leaver with no account can do with the link to her documents: look at them, open
 * each one, answer each one, and ask for a fresh link when hers has stopped working.
 *
 * The token in the address is the whole permission and each of the four checks it again on
 * the server. This class holds no rule of its own; {@see DocumentLinks} does.
 */
class DocumentLinkController extends Controller
{
    /** Every document issued on her exit, and where each stands. Opening it costs one open. */
    public function show(string $token, DocumentLinks $links): Response
    {
        $link = $links->find($token);

        if ($link === null) {
            return $this->refused(ProcessRefused::thatDocumentsLinkNoLongerOpens()->getMessage());
        }

        try {
            $links->refuseUnlessItStillWorks($link, toOpenThePage: true);
        } catch (ProcessRefused $refused) {
            return $this->refused($refused->getMessage(), $token);
        }

        $links->opened($link);

        return $this->thePage($token, $link);
    }

    /** One document's PDF, exactly as stored, found from the row and never from the request. */
    public function file(string $token, int $document, DocumentLinks $links): Response|StreamedResponse
    {
        $link = $links->find($token);

        if ($link === null) {
            return $this->refused(ProcessRefused::thatDocumentsLinkNoLongerOpens()->getMessage());
        }

        try {
            $links->refuseUnlessItStillWorks($link);
        } catch (ProcessRefused $refused) {
            return $this->refused($refused->getMessage(), $token);
        }

        $issued = $this->onItsExit($link, $document);

        return Storage::disk($issued->disk)->response(
            $issued->path,
            // A slash is refused in a file name, and a leaver's name can carry one ("d/o").
            str_replace(['/', '\\'], '-', $issued->name().', '.$link->case->subject?->name.'.pdf'),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    /**
     * Record her answer to one document. A refusal over what was given, such as a reason too
     * short, goes back onto the page with what she typed, since the link is fine; a link that
     * has stopped working is drawn as one.
     */
    public function answer(Request $request, string $token, int $document, DocumentLinks $links): Response
    {
        $given = $request->validate([
            'answer' => ['required', 'string', 'max:20'],
            // Its length is checked by the answer itself, which keeps what she typed on the
            // page. A browser counts a line break as one against the box's limit and sends two.
            'reason' => ['nullable', 'string'],
        ]);

        $link = $links->find($token);

        if ($link === null) {
            return $this->refused(ProcessRefused::thatDocumentsLinkNoLongerOpens()->getMessage());
        }

        $issued = $this->onItsExit($link, $document);

        try {
            $links->answer($link, $issued, $given['answer'], $given['reason'] ?? null);
        } catch (ProcessRefused $refused) {
            try {
                $links->refuseUnlessItStillWorks($link->refresh());
            } catch (ProcessRefused) {
                return $this->refused($refused->getMessage(), $token);
            }

            return $this->thePage($token, $link, [
                'refused' => $refused->getMessage(),
                'refusedFor' => $issued->getKey(),
                'reason' => $given['reason'] ?? null,
            ], 422);
        }

        // Drawn again rather than sent to the page's address, which would cost an open.
        return $this->thePage($token, $link);
    }

    /**
     * Send a fresh link to the address on the record. The address is never read from this
     * request, and the page that follows does not name it.
     */
    public function again(string $token, DocumentLinks $links): Response
    {
        $link = $links->find($token);

        if ($link === null) {
            return $this->refused(ProcessRefused::thatDocumentsLinkNoLongerOpens()->getMessage());
        }

        try {
            $links->sendAgain($link);
        } catch (ProcessRefused $refused) {
            return $this->refused($refused->getMessage());
        }

        return $this->door(
            'A new link is on its way',
            'It has gone to the same address as the last one, and works for the next '
                .StepLink::LastsHours.' hours.',
        );
    }

    /** A document of this link's own exit, or not found, so the address cannot reach another. */
    private function onItsExit(DocumentLink $link, int $document): IssuedDocument
    {
        return IssuedDocument::query()->where('case_id', $link->case_id)->whereKey($document)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $also
     */
    private function thePage(string $token, DocumentLink $link, array $also = [], int $status = 200): Response
    {
        return response()->view('document-link', [
            'token' => $token,
            'case' => $link->case,
            'documents' => $link->case->issuedDocuments()->get(),
            'lastsHours' => StepLink::LastsHours,
            'opens' => StepLink::Opens,
            ...$also,
        ], $status);
    }

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
            'askAgainAt' => $askAgainFor === null ? null : route('document-link.again', $askAgainFor),
        ]), $status);
    }
}
