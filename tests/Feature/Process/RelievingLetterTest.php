<?php

use App\Models\IssuedDocument;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\Letter;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Database\Seeders\VertexSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Module 09 step 1: one relieving letter, from the words Meera wrote to a PDF nobody can
| change.
|
| Neha is leaving Vertex and her exit is open. Meera's wording is written the way Filament's
| editor stores it, a token being a span the editor inserted. It is filled with Neha's
| details by Filament's renderer, cleaned down to text and plain structure, put on our page
| under the letterhead, drawn by dompdf and stored with its SHA-256.
|
| Assertions are on the HTML handed to dompdf and on the stored bytes. No PDF reader is
| installed, and none is needed for what these prove.
*/

beforeEach(function () {
    $this->seed(VertexSeeder::class);

    $this->vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    Storage::fake(config('startx.documents_disk'));
});

function whoIsAtVertex(string $first): User
{
    return User::query()->where('work_email', $first.'@vertex.test')->sole();
}

function nehasOpenExit(): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', whoIsAtVertex('neha')->getKey())->sole();
}

/** A token as Filament's editor stores one: the span it inserted, holding the label it showed. */
function token(string $id, string $label): string
{
    return '<span data-type="mergeTag" data-id="'.$id.'">'.$label.'</span>';
}

function meerasRelievingWording(string $closing = 'Her dues of ₹40,000 have been settled.'): string
{
    return '<p>'.token('letter.issued_on', 'Date of issue').'</p>'
        .'<h2>Relieving letter</h2>'
        .'<p>This is to certify that '.token('person.name', 'Name').', employee code '
        .token('person.employee_code', 'Employee code').', worked with '.token('company.name', 'Company')
        .' as '.token('person.designation', 'Designation').' from '.token('person.joining_date', 'Joining date')
        .' to '.token('case.last_working_day', 'Last working day').'.</p>'
        .'<p><strong>'.$closing.'</strong></p>';
}

/** What a reader of the page sees, with the markup taken away and every entity read. */
function asRead(string $page): string
{
    return html_entity_decode(strip_tags($page), ENT_QUOTES | ENT_HTML5);
}

/**
 * Neha's relieving letter as the page handed to dompdf.
 *
 * @param  array<string, string|null>  $around
 */
function relievingPage(ProcessCase $case, string $wording, array $around = []): string
{
    return (new Letter)->page((new Letter)->tokensFor($case), $wording, $around);
}

function aPngLetterhead(): string
{
    return UploadedFile::fake()->image('letterhead.png', 1200, 200)->getContent();
}

it('fills Meera\'s wording with Neha\'s details from her exit', function () {
    // Half past midnight in Nashik is still the 25th on the server's clock.
    $this->travelTo(CarbonImmutable::parse('2026-11-26 00:30', 'Asia/Kolkata'));

    TenantContext::run($this->vertex, function () {
        $exit = nehasOpenExit();
        $record = $exit->subjectEmploymentRecord;

        expect(asRead(relievingPage($exit, meerasRelievingWording())))
            ->toContain('26 November 2026')
            ->toContain('This is to certify that Neha Deshpande, employee code '.$record->employee_code
                .', worked with Vertex Foods as Quality Executive from '.$record->joining_date->format('j F Y')
                .' to '.$exit->statutory_from->format('j F Y').'.')
            ->toContain('Her dues of ₹40,000 have been settled.');
    });
});

it('stores the PDF on the documents disk and records its SHA-256, the case and who issued it', function () {
    TenantContext::run($this->vertex, function () {
        $exit = nehasOpenExit();
        $meera = whoIsAtVertex('meera');

        $issued = (new Letter)->issue($exit, IssuedDocument::RelievingLetter, relievingPage($exit, meerasRelievingWording(), ['letterhead' => aPngLetterhead()]), $meera);
        $stored = Storage::disk(config('startx.documents_disk'))->get($issued->path);

        expect($stored)->toStartWith('%PDF-')
            // The engine's own font, the one with the rupee sign in it.
            ->toMatch('#/BaseFont /[A-Z]{6}\+DejaVuSans\n#')
            ->and(hash('sha256', $stored))->toBe($issued->sha256)
            ->and($issued->fresh()->only(['case_id', 'kind', 'disk', 'issued_by']))->toBe([
                'case_id' => $exit->getKey(),
                'kind' => IssuedDocument::RelievingLetter,
                'disk' => config('startx.documents_disk'),
                'issued_by' => $meera->getKey(),
            ])
            ->and($issued->path)->toStartWith('issued-documents/'.$this->vertex->getKey().'/');
    });
});

it('names the company by its registered name when it has one', function () {
    $this->vertex->update(['legal_name' => 'Vertex Foods Private Limited']);

    TenantContext::run($this->vertex, function () {
        expect(asRead(relievingPage(nehasOpenExit(), meerasRelievingWording())))
            ->toContain('worked with Vertex Foods Private Limited as Quality Executive');
    });
});

it('refuses a letter too long for the cleaner rather than storing it cut short or blank', function () {
    TenantContext::run($this->vertex, function () {
        // Past the cleaner's limit, where its cut would land inside a ₹ and leave nothing.
        $wording = '<p>'.str_repeat('₹', 200000).'</p>';

        expect(fn () => (new Letter)->issue(nehasOpenExit(), IssuedDocument::RelievingLetter, relievingPage(nehasOpenExit(), $wording), whoIsAtVertex('meera')))
            ->toThrow(InvalidArgumentException::class, 'A letter longer than 500000 bytes is refused, not cut short.')
            ->and(Storage::disk(config('startx.documents_disk'))->allFiles())->toBe([])
            ->and(IssuedDocument::query()->count())->toBe(0);
    });
});

it('prints a token the letter does not have as nothing, rather than failing or resolving it', function () {
    TenantContext::run($this->vertex, function () {
        $wording = '<p>Salary: ['.token('person.salary', 'Salary').'] Password: ['.token('subject.password', 'Password').']</p>';

        expect(asRead(relievingPage(nehasOpenExit(), $wording)))
            ->toContain('Salary: [] Password: []');
    });
});

it('prints a name that is markup as the characters of the name', function () {
    TenantContext::run($this->vertex, function () {
        whoIsAtVertex('neha')->update(['first_name' => '<img src=x onerror=alert(1)><b>Neha</b>']);

        $page = relievingPage(nehasOpenExit()->fresh(), meerasRelievingWording());

        expect($page)->not->toContain('<img')->not->toContain('<b>')
            ->and(asRead($page))->toContain('certify that <img src=x onerror=alert(1)><b>Neha</b> Deshpande, employee code');
    });
});

it('keeps template and PHP syntax Meera typed as the text she typed', function () {
    TenantContext::run($this->vertex, function () {
        // How the editor stores what she typed: the brackets escaped, as text.
        $typed = '<p>{{ 1 + 1 }} | {!! 6 * 6 !!} | &lt;?php echo 7 * 7; ?&gt; | @php echo 8 * 8; @endphp | {{ $case->subject->password }}</p>';

        expect(asRead(relievingPage(nehasOpenExit(), $typed)))
            ->toContain('{{ 1 + 1 }} | {!! 6 * 6 !!} | <?php echo 7 * 7; ?> | @php echo 8 * 8; @endphp | {{ $case->subject->password }}')
            ->not->toContain('36')->not->toContain('49')->not->toContain('64');
    });
});

it('runs nothing sent as raw PHP instead of through the editor', function () {
    TenantContext::run($this->vertex, function () {
        $page = relievingPage(nehasOpenExit(), '<p>Before <?php echo 7 * 7; ?> after</p>');

        expect($page)->not->toContain('<?php')->not->toContain('49')
            ->and(asRead($page))->toContain('Before');
    });
});

it('hands dompdf none of an image, a style, a stylesheet, an SVG or a font pointing at a file', function (string $hostile) {
    TenantContext::run($this->vertex, function () use ($hostile) {
        $page = relievingPage(nehasOpenExit(), '<p>Dear Neha,</p>'.$hostile);

        expect($page)
            ->not->toContain('passwd')->not->toContain('.env')->not->toContain('url(')
            ->not->toContain('<img')->not->toContain('<svg')->not->toContain('<link')
            ->not->toContain('<object')->not->toContain('<iframe')->not->toContain('style=')
            ->not->toContain('src=')->not->toContain('href=')
            ->and(substr_count($page, '<style'))->toBe(1, 'Only our own page\'s stylesheet.')
            ->and(asRead($page))->toContain('Dear Neha,');
    });
})->with([
    'an image named by path' => '<p><img src="file:///etc/passwd"></p>',
    'an image named relatively' => '<p><img src="../.env"></p>',
    'an image Filament attached' => '<p><img data-id="../../.env" src="/storage/../.env"></p>',
    'an SVG inside an image' => '<p><img src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd"/></svg>').'"></p>',
    'an SVG in the page' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd" /></svg>',
    'a style on a paragraph' => '<p style="background: url(file:///etc/passwd)">Regards</p>',
    'a style on a text colour' => '<p><span data-color="x" style="background-image: url(\'/etc/passwd\')">Regards</span></p>',
    'a stylesheet in the page' => '<style>p { background: url("../.env") }</style>',
    'a font pointing at a file' => '<style>@font-face { font-family: x; src: url("../.env"); }</style>',
    'a linked stylesheet' => '<link rel="stylesheet" href="file:///etc/passwd">',
    'an embedded object' => '<object data="file:///etc/passwd"></object><iframe src="file:///etc/passwd"></iframe>',
    'a link to a file' => '<p><a href="file:///etc/passwd">Regards</a></p>',
]);

it('places a PNG letterhead inside the page itself, so dompdf opens no file for it', function () {
    TenantContext::run($this->vertex, function () {
        expect(relievingPage(nehasOpenExit(), meerasRelievingWording(), ['letterhead' => aPngLetterhead()]))
            ->toContain('<img class="letterhead" src="data:image/png;base64,');
    });
});

it('refuses a letterhead that is not a PNG or a JPEG', function () {
    TenantContext::run($this->vertex, function () {
        relievingPage(nehasOpenExit(), meerasRelievingWording(), ['letterhead' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd"/></svg>']);
    });
})->throws(InvalidArgumentException::class, 'A letter\'s picture is a PNG or a JPEG.');

it('keeps the letter Neha was given byte for byte when Meera\'s wording changes and the letter is issued again', function () {
    TenantContext::run($this->vertex, function () {
        $exit = nehasOpenExit();
        $meera = whoIsAtVertex('meera');
        $disk = Storage::disk(config('startx.documents_disk'));

        $first = (new Letter)->issue($exit, IssuedDocument::RelievingLetter, relievingPage($exit, meerasRelievingWording()), $meera);
        $given = $disk->get($first->path);

        $again = (new Letter)->issue($exit, IssuedDocument::RelievingLetter, relievingPage($exit, meerasRelievingWording('We wish her well.')), $meera);

        expect($again->path)->not->toBe($first->path)
            ->and($disk->get($first->path))->toBe($given)
            ->and(hash('sha256', $given))->toBe($first->fresh()->sha256)
            ->and($disk->get($again->path))->not->toBe($given);
    });
});

/*
| dompdf's own settings, below the cleaner. A letter never reaches it naming a file, so these
| hand it one directly: the wall behind the wall.
*/

it('has dompdf refuse a file anywhere in the project, and draw an image handed over inside the page', function () {
    $png = aPngLetterhead();
    $inTheProject = storage_path('framework/testing/letterhead-probe.png');
    file_put_contents($inTheProject, $png);

    try {
        $named = Pdf::loadHTML('<img src="'.$inTheProject.'"><img src="file://'.$inTheProject.'">')->output();
        $inside = Pdf::loadHTML('<img src="data:image/png;base64,'.base64_encode($png).'">')->output();
    } finally {
        unlink($inTheProject);
    }

    expect($inside)->toContain('/Subtype /Image')
        ->and($named)->not->toContain('/Subtype /Image');
});

/*
| The record. A refused statement abandons the test's own transaction in Postgres, so each
| expected refusal has a test to itself.
*/

it('refuses a change to what an issued letter\'s row says at the database', function () {
    TenantContext::run($this->vertex, function () {
        (new Letter)->issue(nehasOpenExit(), IssuedDocument::RelievingLetter, relievingPage(nehasOpenExit(), meerasRelievingWording()), whoIsAtVertex('meera'));

        DB::table('issued_documents')->update(['sha256' => str_repeat('0', 64)]);
    });
})->throws(QueryException::class, 'issued_documents keeps what was issued; update refused.');

it('refuses a removal of an issued letter\'s row at the database', function () {
    TenantContext::run($this->vertex, function () {
        (new Letter)->issue(nehasOpenExit(), IssuedDocument::RelievingLetter, relievingPage(nehasOpenExit(), meerasRelievingWording()), whoIsAtVertex('meera'));

        DB::table('issued_documents')->delete();
    });
})->throws(QueryException::class, 'issued_documents keeps what was issued; delete refused.');

it('refuses to empty the issued documents wholesale', function () {
    DB::statement('truncate issued_documents');
})->throws(QueryException::class, 'issued_documents keeps what was issued; truncate refused.');
