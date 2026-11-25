<?php

namespace App\Process;

use App\Models\IssuedDocument;
use App\Models\ProcessCase;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Tiptap\Editor;

/**
 * A relieving letter: the client's wording with the leaver's details in it, drawn as a PDF
 * and kept exactly as it was drawn.
 *
 * **Everything between the client's editor and the PDF engine is a security boundary.** The
 * engine reads any file or image the HTML names, and the wording is HTML a client
 * administrator wrote. So the HTML that reaches it is only ever three things: our own page,
 * the leaver's details as plain text, and the client's wording after a cleaner far
 * narrower than Filament's own, which keeps inline styles and images for the screens.
 *
 * Tokens are Filament's, filled by Filament's renderer. A token is only something inserted
 * from the editor's list, never text that looks like one, so `{{ 1 + 1 }}` typed in a letter
 * prints as typed. A token the list does not have gets no value and prints as nothing.
 * `App\Notifications\Wording` is not used: it escapes nothing, which is right for a
 * plain-text email and wrong for HTML.
 */
final class Letter
{
    /** In bytes, the filled wording's limit, past which the cleaner would cut it. */
    private const LongestWording = 500000;

    /**
     * The leaver's details a relieving letter may name, keyed by token.
     *
     * Read from the job row the exit pinned when it opened, as the exit's page reads them, and
     * the last working day from the exit itself, which HR can correct. A fact never recorded
     * prints as nothing rather than as a placeholder, because the page is a letter.
     *
     * @return array<string, string>
     */
    public function tokensFor(ProcessCase $case): array
    {
        $record = $case->subjectEmploymentRecord;

        return [
            'person.name' => $case->subject->name,
            'person.employee_code' => $record?->employee_code ?? '',
            'person.designation' => $record?->recorded_designation_name ?? '',
            'person.joining_date' => $record?->joining_date?->format('j F Y') ?? '',
            'case.last_working_day' => ($case->statutory_from ?? $record?->last_working_day)?->format('j F Y') ?? '',
            // The registered name when the company has one: the setup screen promises it
            // is the one on letters.
            'company.name' => $case->tenant->legal_name ?: $case->tenant->name,

            // The company's own calendar: the letter is the company's, dated where it is.
            'letter.issued_on' => now($case->tenant->timezone)->format('j F Y'),
        ];
    }

    /**
     * The whole HTML page handed to the PDF engine.
     *
     * @param  string|null  $letterhead  the image's bytes; a PNG or a JPEG and nothing else
     */
    public function page(ProcessCase $case, string $wording, ?string $letterhead): string
    {
        // Filament's renderer looks every image in the wording up on a disk, by a path the
        // wording itself carries, and writes a signed address to the file into the page. A
        // letter has no images, so it looks nothing up.
        $renderer = new class($wording) extends RichContentRenderer
        {
            protected function processFileAttachments(Editor $editor): void {}
        };

        // Without Filament's own cleaner, because ours replaces it: the output goes nowhere
        // but through the cleaner below.
        $filled = $renderer->mergeTags($this->tokensFor($case))->toUnsafeHtml();

        // The cleaner cuts anything longer, and a cut inside a character such as ₹ makes
        // it return nothing at all: a blank letter, kept for good. No real letter comes
        // near this, so one that does is refused rather than shortened.
        if (strlen($filled) > self::LongestWording) {
            throw new InvalidArgumentException('A letter longer than '.self::LongestWording.' bytes is refused, not cut short.');
        }

        return view('letter', [
            'body' => $this->cleaner()->sanitize($filled),
            'letterhead' => $letterhead === null ? null : $this->asAnImage($letterhead),
        ])->render();
    }

    /**
     * Draw the letter, put the PDF on the documents disk, and record it with its SHA-256.
     *
     * ponytail: a row refused after the file is written leaves the file behind with nothing
     * naming it. Sweep such files if they are ever found; nothing reads them.
     */
    public function issue(ProcessCase $case, string $wording, ?string $letterhead, User $by): IssuedDocument
    {
        $pdf = Pdf::loadHTML($this->page($case, $wording, $letterhead))->output();

        $disk = (string) config('startx.documents_disk');
        $path = 'issued-documents/'.$case->tenant_id.'/'.Str::random(40).'.pdf';

        if (! Storage::disk($disk)->put($path, $pdf)) {
            throw new RuntimeException("The relieving letter for case {$case->getKey()} could not be stored.");
        }

        return IssuedDocument::create([
            'case_id' => $case->getKey(),
            'kind' => IssuedDocument::RelievingLetter,
            'disk' => $disk,
            'path' => $path,
            'sha256' => hash('sha256', $pdf),
            'issued_by' => $by->getKey(),
        ]);
    }

    /**
     * What may survive of the client's wording: text, and the plain structure a letter is
     * laid out with. No attribute at all, so no style, no class and no address of any kind.
     *
     * Anything not named is removed with what it holds, which is what an image, a
     * stylesheet, an SVG or an embedded object should be. The few below that hold the
     * client's own words, a link or a highlight among them, lose the element and keep the
     * words.
     */
    private function cleaner(): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig;

        foreach (['p', 'br', 'strong', 'em', 'u', 's', 'sub', 'sup', 'small', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as $element) {
            $config = $config->allowElement($element);
        }

        foreach (['span', 'a', 'div', 'mark', 'code', 'pre', 'details', 'summary'] as $element) {
            $config = $config->blockElement($element);
        }

        // Symfony's default cuts anything past 20,000 characters without a word. Ours is
        // Filament's own, and `page()` refuses a letter past it before it gets here.
        return new HtmlSanitizer($config->withMaxInputLength(self::LongestWording));
    }

    /**
     * The letterhead as part of the page itself, so the engine never opens a file for it.
     *
     * The type is read from the bytes, not from a file name: an SVG is the engine's own
     * documented way to read a file, and it would not care what the upload was called.
     */
    private function asAnImage(string $bytes): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($type, ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('A letterhead is a PNG or a JPEG.');
        }

        return 'data:'.$type.';base64,'.base64_encode($bytes);
    }
}
