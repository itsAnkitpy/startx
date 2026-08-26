<?php

namespace App\Http\Controllers;

use App\Models\ProcessCase;
use App\Process\CaseDocuments;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opening a document that was attached to a step.
 *
 * **Ours rather than a link storage signs for us**, and the difference is the whole point.
 * A signed address answers "may this be opened" once, at the moment it is made, and then
 * anything holding that address can open the file for as long as it lasts — no company,
 * no person, no case. These are exit clearance documents about a named individual. So the
 * address here carries no path and no signature, and the question is asked again, of the
 * person signed in, every single time.
 *
 * The file itself never becomes reachable: it sits on a disk nothing on the web can get
 * at, and this streams it through with headers we choose.
 */
class CaseDocumentController extends Controller
{
    /**
     * The two kinds a browser can actually put on the screen are shown there; the rest
     * download, because that is all a browser will do with them anyway.
     */
    private const ShownInTheBrowser = ['pdf', 'jpg', 'jpeg', 'png'];

    public function show(ProcessCase $case, int $sequence, string $question, CaseDocuments $documents): StreamedResponse
    {
        // Not-found rather than not-allowed, and the same answer for every way of being
        // wrong: a case in another company, a case this person has no business with, a
        // step nobody answered, a question that holds typed words rather than a file.
        // Told apart, this address becomes a way of asking which exits exist and what
        // they collected, one guess at a time.
        abort_unless($documents->mayBeOpenedBy($case, auth()->user()), 404);

        $document = $documents->find($case, $sequence, $question);

        abort_if($document === null, 404);

        $extension = strtolower(pathinfo($document['path'], PATHINFO_EXTENSION));

        return Storage::disk($document['disk'])->response(
            $document['path'],
            $document['name'],
            [
                // The kind recorded when the file was taken, not a kind guessed now from
                // its contents. A browser that sniffs a type for itself is a browser that
                // can be talked into running something, and this is a file an employee
                // chose.
                'Content-Type' => $document['type'] ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
            in_array($extension, self::ShownInTheBrowser, true) ? 'inline' : 'attachment',
        );
    }
}
