<?php

namespace App\Http\Controllers;

use App\Models\IssuedDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opening a document issued on an exit, asked again of the person signed in every time.
 *
 * A letter opens for whoever may see the person on the exit's page, the statement only for
 * whoever may see the money on it (P7), the same two questions the page asks before it
 * lists them. Another company's document is never found at all.
 */
class IssuedDocumentController extends Controller
{
    public function show(IssuedDocument $document): StreamedResponse
    {
        // Not-found rather than not-allowed, as for an attached file, so the address cannot
        // be used to learn which exits have a statement.
        abort_unless(auth()->user()->can($document->openedBy(), $document->case), 404);

        return Storage::disk($document->disk)->response(
            $document->path,
            // A slash is refused in a file name, and a leaver's name can carry one ("d/o").
            str_replace(['/', '\\'], '-', $document->name().', '.$document->case->subject?->name.'.pdf'),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
