<?php

namespace App\Process;

use App\Models\IssuedDocument;
use App\Models\ProcessCase;
use App\Models\Tenant;
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
 * A leaver's letter, relieving or experience: the client's wording with the leaver's details
 * in it, drawn as a PDF and kept exactly as it was drawn.
 *
 * **Everything between the client's editor and the PDF engine is a security boundary.** The
 * engine reads any file or image the HTML names, and the wording is HTML a client
 * administrator wrote. So the HTML that reaches it is only ever: our own page; the leaver's
 * details and the signer's name and designation, as plain text; the letterhead, footer and
 * signature, each a PNG or JPEG's own bytes inside the page; and the client's wording after
 * a cleaner far narrower than Filament's own, which keeps inline styles and images for the
 * screens.
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

    /** The letters a client writes, as the letters screen names them. */
    public const Kinds = [
        IssuedDocument::RelievingLetter => 'Relieving letter',
        IssuedDocument::ExperienceLetter => 'Experience letter',
    ];

    /** Every token a letter may name, as the editor's side panel lists it. */
    public const Tokens = [
        'person.name' => 'Name',
        'person.employee_code' => 'Employee code',
        'person.designation' => 'Designation',
        'person.joining_date' => 'Joining date',
        'case.last_working_day' => 'Last working day',
        'company.name' => 'Company name',
        'letter.issued_on' => 'Date of issue',
    ];

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
            ...$this->companyTokens($case->tenant),
        ];
    }

    /**
     * Made-up details for the preview, so Meera reads her page as a letter before any leaver
     * has one. The company and the date are her company's own.
     *
     * @return array<string, string>
     */
    public function sampleTokens(Tenant $company): array
    {
        return [
            'person.name' => 'Arjun Mehta',
            'person.employee_code' => 'EMP-0417',
            'person.designation' => 'Senior Executive',
            'person.joining_date' => now($company->timezone)->subYears(3)->startOfMonth()->format('j F Y'),
            'case.last_working_day' => now($company->timezone)->format('j F Y'),
            ...$this->companyTokens($company),
        ];
    }

    /**
     * The wording a letter prints until the company writes its own: plain and generic, and
     * nobody's policy (P1).
     */
    public static function starterWording(string $kind): string
    {
        $token = static fn (string $name): string => '<span data-type="mergeTag" data-id="'.$name.'"></span>';

        return match ($kind) {
            IssuedDocument::RelievingLetter => '<p>'.$token('letter.issued_on').'</p>'
                .'<p>'.$token('person.name').'<br>Employee code: '.$token('person.employee_code').'</p>'
                .'<h3>Relieving letter</h3>'
                .'<p>Dear '.$token('person.name').',</p>'
                .'<p>This is to confirm that you have been relieved of your duties as '.$token('person.designation')
                .' at '.$token('company.name').' with effect from the close of business on '
                .$token('case.last_working_day').'. You were with us from '.$token('person.joining_date').'.</p>'
                .'<p>We thank you for your work with us and wish you well in the future.</p>'
                .'<p>Yours sincerely,<br>For '.$token('company.name').'</p>',
            IssuedDocument::ExperienceLetter => '<p>'.$token('letter.issued_on').'</p>'
                .'<h3>Experience letter</h3>'
                .'<p><strong>To whom it may concern</strong></p>'
                .'<p>This is to certify that '.$token('person.name').', employee code '.$token('person.employee_code')
                .', worked with '.$token('company.name').' from '.$token('person.joining_date').' to '
                .$token('case.last_working_day').'. At the time of leaving, '.$token('person.name')
                .' held the designation of '.$token('person.designation').'.</p>'
                .'<p>We wish '.$token('person.name').' every success in the future.</p>'
                .'<p>For '.$token('company.name').'</p>',
        };
    }

    /**
     * @return array{'company.name': string, 'letter.issued_on': string}
     */
    private function companyTokens(Tenant $company): array
    {
        return [
            // The registered name when the company has one: the setup screen promises it
            // is the one on letters.
            'company.name' => $company->legal_name ?: $company->name,

            // The company's own calendar: the letter is the company's, dated where it is.
            'letter.issued_on' => now($company->timezone)->format('j F Y'),
        ];
    }

    /**
     * The whole HTML page handed to the PDF engine.
     *
     * @param  array<string, string>  $tokens  each token's value, printed as plain text
     * @param  string|array<string, mixed>  $wording  as Filament's editor stores it, or holds it before a save
     * @param  array{letterhead?: string|null, footer?: string|null, signature?: string|null, signed_by?: string, signed_as?: string}  $around  what the page puts around the wording; each picture is its bytes, a PNG or a JPEG and nothing else
     */
    public function page(array $tokens, string|array $wording, array $around = []): string
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
        $filled = $renderer->mergeTags($tokens)->toUnsafeHtml();

        // The cleaner cuts anything longer, and a cut inside a character such as ₹ makes
        // it return nothing at all: a blank letter, kept for good. No real letter comes
        // near this, so one that does is refused rather than shortened.
        if (strlen($filled) > self::LongestWording) {
            throw new InvalidArgumentException('A letter longer than '.self::LongestWording.' bytes is refused, not cut short.');
        }

        return view('letter', [
            'body' => $this->cleaner()->sanitize($filled),
            'letterhead' => $this->asAnImage($around['letterhead'] ?? null),
            'footer' => $this->asAnImage($around['footer'] ?? null),
            'signature' => $this->asAnImage($around['signature'] ?? null),
            'signedBy' => $around['signed_by'] ?? '',
            'signedAs' => $around['signed_as'] ?? '',
        ])->render();
    }

    /**
     * Draw the letter, put the PDF on the documents disk, and record it with its SHA-256.
     *
     * ponytail: a row refused after the file is written leaves the file behind with nothing
     * naming it. Sweep such files if they are ever found; nothing reads them.
     *
     * @param  array{letterhead?: string|null, footer?: string|null, signature?: string|null, signed_by?: string, signed_as?: string}  $around
     */
    public function issue(ProcessCase $case, string $wording, array $around, User $by): IssuedDocument
    {
        $pdf = $this->pdf($this->page($this->tokensFor($case), $wording, $around));

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

    /** The PDF's bytes, drawn from the page. */
    public function pdf(string $page): string
    {
        return Pdf::loadHTML($page)->output();
    }

    /**
     * The wording as it prints, its tokens still in it, for saving. What the letter would
     * drop, such as a centring, a colour, a link or a picture pasted in from elsewhere, is
     * dropped before it is kept, so the editor shows what the leaver is given.
     */
    public function asPrinted(string $wording): string
    {
        return $this->cleaner(keepingTokens: true)->sanitize($wording);
    }

    /**
     * What may survive of the client's wording: text, and the plain structure a letter is
     * laid out with. No attribute at all, so no style, no class and no address of any kind.
     *
     * Anything not named is removed with what it holds, which is what an image, a
     * stylesheet, an SVG or an embedded object should be. The few below that hold the
     * client's own words, a link or a highlight among them, lose the element and keep the
     * words.
     *
     * Keeping the tokens is for the wording as it is saved: each one is an empty span
     * naming it, which the letter fills only when it is drawn.
     */
    private function cleaner(bool $keepingTokens = false): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig;

        foreach (['p', 'br', 'strong', 'em', 'u', 's', 'sub', 'sup', 'small', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as $element) {
            $config = $config->allowElement($element);
        }

        foreach (['span', 'a', 'div', 'mark', 'code', 'pre', 'details', 'summary'] as $element) {
            $config = $config->blockElement($element);
        }

        if ($keepingTokens) {
            // Nothing cut on the way in: a letter too long to draw is refused when drawn.
            return new HtmlSanitizer($config->allowElement('span', ['data-type', 'data-id'])->withMaxInputLength(-1));
        }

        // Symfony's default cuts anything past 20,000 characters without a word. Ours is
        // Filament's own, and `page()` refuses a letter past it before it gets here.
        return new HtmlSanitizer($config->withMaxInputLength(self::LongestWording));
    }

    /**
     * A picture as part of the page itself, so the engine never opens a file for it.
     *
     * The type is read from the bytes, not from a file name: an SVG is the engine's own
     * documented way to read a file, and it would not care what the upload was called.
     */
    private function asAnImage(?string $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($type, ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('A letter\'s picture is a PNG or a JPEG.');
        }

        return 'data:'.$type.';base64,'.base64_encode($bytes);
    }
}
