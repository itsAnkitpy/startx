<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Pages\Letters;
use App\Models\IssuedDocument;
use App\Models\LetterSetup;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Process\Letter;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DrawnPdf;
use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\RichEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Module 09 step 2: the screen where Meera writes Vertex's relieving and experience letters,
| uploads the letterhead, footer strip and signature, and previews the PDF.
|
| Run against the demo company as seeded. What reaches the PDF engine is read as the HTML
| handed to it, as step 1's tests do; no PDF reader is installed.
*/

beforeEach(function () {
    $this->seed(VertexSeeder::class);

    $this->vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    Storage::fake(config('startx.documents_disk'));
});

function whoWritesVertexsLetters(string $first): User
{
    return User::query()->where('work_email', $first.'@vertex.test')->sole();
}

/** A real picture, drawn by GD, as the browser would upload it. */
function aLetterPicture(string $name, int $width = 1200, int $height = 200): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

/** What a reader of the page sees, with the markup taken away and every entity read. */
function asTheLetterReads(string $page): string
{
    return html_entity_decode(strip_tags($page), ENT_QUOTES | ENT_HTML5);
}

function previewOf(string $kind): TestAction
{
    return TestAction::make('preview'.str($kind)->studly())->schemaComponent($kind.'_section');
}

it('gives each letter a plain starter wording at both demo companies, with nothing seeded and none of it Holisol\'s', function () {
    $this->seed(MeridianSeeder::class);

    foreach ([VertexSeeder::Slug, MeridianSeeder::Slug] as $slug) {
        $company = Tenant::query()->where('slug', $slug)->sole();

        TenantContext::run($company, function () use ($company) {
            $letter = new Letter;
            $sample = $letter->sampleTokens($company);

            foreach (array_keys(Letter::Kinds) as $kind) {
                $reads = asTheLetterReads($letter->page($sample, LetterSetup::current()->wordingOf($kind)));

                // Every token the starter names is one the letter fills.
                foreach ($sample as $value) {
                    expect($reads)->toContain($value);
                }

                expect($reads)->not->toContain('Holisol')->not->toContain('HLPL')->not->toContain('bond');
            }

            expect(LetterSetup::query()->count())->toBe(0);
        });
    }
});

it('fills every token the editor offers, from an exit and for the preview alike', function () {
    TenantContext::run($this->vertex, function () {
        $nehasExit = ProcessCase::query()->where('subject_user_id', whoWritesVertexsLetters('neha')->getKey())->sole();

        expect(array_keys((new Letter)->tokensFor($nehasExit)))->toEqualCanonicalizing(array_keys(Letter::Tokens))
            ->and(array_keys((new Letter)->sampleTokens($this->vertex)))->toEqualCanonicalizing(array_keys(Letter::Tokens));
    });
});

it('keeps what Meera writes and uploads, prints it on Neha\'s letter, and saving again copies nothing', function () {
    TenantContext::run($this->vertex, function () {
        $screen = Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class)
            ->assertOk()
            ->fillForm([
                'relieving_letter' => '<p>Dear <span data-type="mergeTag" data-id="person.name"></span>, you are relieved.</p>',
                'letterhead' => aLetterPicture('letterhead.png'),
                'footer' => aLetterPicture('footer.jpg', 1600, 120),
                'signature' => aLetterPicture('signature.png', 440, 140),
                'signed_by' => 'Meera Joshi',
                'signed_as' => 'Head of People',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Letters saved.');

        $setup = LetterSetup::current();
        $disk = Storage::disk(config('startx.documents_disk'));
        $folder = 'letter-pictures/'.$this->vertex->getKey();

        expect($setup->exists)->toBeTrue()
            ->and($setup->relieving_letter)->toContain('you are relieved')
            ->and($setup->experience_letter)->toContain('To whom it may concern')
            ->and($disk->allFiles($folder))->toHaveCount(3)
            ->and([$setup->letterhead, $setup->footer, $setup->signature])->each->toStartWith($folder.'/');

        $nehasExit = ProcessCase::query()->where('subject_user_id', whoWritesVertexsLetters('neha')->getKey())->sole();
        $page = (new Letter)->page(
            (new Letter)->tokensFor($nehasExit),
            $setup->wordingOf(IssuedDocument::RelievingLetter),
            $setup->around(),
        );

        expect(substr_count($page, 'src="data:image/'))->toBe(3)
            ->and(asTheLetterReads($page))->toContain('Dear Neha Deshpande, you are relieved.')
            ->toContain('Meera Joshi')->toContain('Head of People');

        $screen->call('save')->assertHasNoFormErrors();

        expect($disk->allFiles($folder))->toHaveCount(3)
            ->and(LetterSetup::current()->letterhead)->toBe($setup->letterhead);
    });
});

it('drops what the letter does not print when Meera saves a letter pasted in, and the editor shows her what is kept', function () {
    TenantContext::run($this->vertex, function () {
        $pasted = '<h2 style="text-align: center">Relieving letter</h2>'
            .'<p>Dear <span data-type="mergeTag" data-id="person.name"></span>, <mark>please</mark> read <a href="https://example.com/policy">our policy</a>.</p>'
            .'<p><img src="https://tracker.example/pixel.png"></p>';

        $screen = Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class)
            ->fillForm(['relieving_letter' => $pasted]);

        // What the editor holds once it is pasted: the centring, the link and the picture.
        expect(json_encode($screen->get('data.relieving_letter')))
            ->toContain('center')->toContain('example.com')->toContain('tracker.example');

        $screen->call('save')->assertHasNoFormErrors();

        expect(LetterSetup::current()->relieving_letter)
            ->toContain('<span data-type="mergeTag" data-id="person.name"></span>')
            ->toContain('please')->toContain('our policy')
            ->not->toContain('center')->not->toContain('<mark')->not->toContain('example.com')->not->toContain('<img')
            ->and(json_encode($screen->get('data.relieving_letter')))
            ->not->toContain('center')->not->toContain('example.com')->not->toContain('tracker.example');
    });
});

it('refuses to draw a letter whose saved signature cannot be read, rather than leave the signature off', function () {
    TenantContext::run($this->vertex, function () {
        LetterSetup::query()->create(['signature' => 'letter-pictures/'.$this->vertex->getKey().'/gone.png']);

        expect(fn () => LetterSetup::current()->around())
            ->toThrow(RuntimeException::class, 'The signature saved for this company cannot be read');
    });
});

it('refuses a picture the letter cannot print, saying why', function (Closure $file, string $why) {
    TenantContext::run($this->vertex, function () use ($file, $why) {
        Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class)
            ->fillForm(['letterhead' => $file()])
            ->call('save')
            ->assertHasFormErrors(['letterhead'])
            ->assertSee($why);

        expect(LetterSetup::query()->count())->toBe(0)
            ->and(Storage::disk(config('startx.documents_disk'))->allFiles('letter-pictures'))->toBe([]);
    });
})->with([
    'an SVG named as a PNG' => [
        fn () => UploadedFile::fake()->createWithContent('letterhead.png', '<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd"/></svg>'),
        'This needs to be a PNG or JPEG picture.',
    ],
    'a PNG with its body cut off' => [
        fn () => UploadedFile::fake()->createWithContent('letterhead.png', substr($png = aLetterPicture('x.png')->getContent(), 0, intdiv(strlen($png), 2))),
        'This picture is damaged and would print as an empty box.',
    ],
    'a picture wider than any letterhead' => [
        fn () => aLetterPicture('letterhead.png', 3001, 100),
        'This picture is too large. It can be at most 3,000 pixels wide and 1,500 pixels high.',
    ],
]);

it('refuses a picture path the browser sends back that Vertex never saved, on save and on preview', function () {
    TenantContext::run($this->vertex, function () {
        $somebodyElses = 'case-documents/999/statement.png';
        Storage::disk(config('startx.documents_disk'))->put($somebodyElses, aLetterPicture('x.png')->getContent());
        Pdf::shouldReceive('loadHTML')->never();

        Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class)
            ->set('data.letterhead', ['tampered' => $somebodyElses])
            ->call('save')
            ->assertHasFormErrors(['letterhead'])
            ->callAction(previewOf(IssuedDocument::RelievingLetter))
            ->assertHasFormErrors(['letterhead']);

        expect(LetterSetup::query()->count())->toBe(0);
    });
});

it('previews the letter as it stands on the screen, with made-up details, and saves nothing', function () {
    TenantContext::run($this->vertex, function () {
        $handedToDompdf = null;

        $drawn = Mockery::mock(DrawnPdf::class);
        $drawn->shouldReceive('output')->andReturn('%PDF-preview');

        Pdf::shouldReceive('loadHTML')->once()->andReturnUsing(function (string $html) use (&$handedToDompdf, $drawn): DrawnPdf {
            $handedToDompdf = $html;

            return $drawn;
        });

        Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class)
            ->fillForm([
                'relieving_letter' => '<p>Trying a sentence for <span data-type="mergeTag" data-id="person.name"></span>.</p>',
                'letterhead' => aLetterPicture('letterhead.png'),
                'signed_by' => 'Meera Joshi',
            ])
            ->callAction(previewOf(IssuedDocument::RelievingLetter))
            ->assertHasNoFormErrors()
            ->assertFileDownloaded('relieving-letter-preview.pdf', '%PDF-preview');

        expect(asTheLetterReads($handedToDompdf))->toContain('Trying a sentence for Arjun Mehta.')->toContain('Meera Joshi')
            ->and($handedToDompdf)->toContain('<img class="letterhead" src="data:image/png;base64,')
            ->and(LetterSetup::query()->count())->toBe(0)
            ->and(Storage::disk(config('startx.documents_disk'))->allFiles('letter-pictures'))->toBe([]);
    });
});

it('tells Meera a letter is empty or too long instead of failing when it is drawn', function () {
    TenantContext::run($this->vertex, function () {
        Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class)
            ->fillForm([
                'relieving_letter' => '<p>'.str_repeat('a', 20001).'</p>',
                // The last line is a blank line as Word pastes it.
                'experience_letter' => '<p></p><p> </p><p>&nbsp;&nbsp;</p>',
            ])
            ->call('save')
            ->assertHasFormErrors(['relieving_letter', 'experience_letter'])
            ->assertSee('must not be greater than 20000 characters')
            ->assertSee('A letter needs some wording.');

        expect(LetterSetup::query()->count())->toBe(0);
    });
});

it('offers only formatting the letter prints', function () {
    $printsAs = [
        'bold' => '<strong>x</strong>',
        'italic' => '<em>x</em>',
        'underline' => '<u>x</u>',
        'h2' => '<h2>x</h2>',
        'h3' => '<h3>x</h3>',
        'bulletList' => '<ul><li>x</li></ul>',
        'orderedList' => '<ol><li>x</li></ol>',
    ];

    TenantContext::run($this->vertex, function () use ($printsAs) {
        $screen = Livewire::actingAs(whoWritesVertexsLetters('meera'))->test(Letters::class);

        $editors = collect($screen->instance()->form->getFlatFields(withHidden: true))
            ->filter(fn ($field): bool => $field instanceof RichEditor);

        expect($editors->map->getName()->values()->all())->toBe(array_keys(Letter::Kinds));

        $editors->each(function (RichEditor $editor) use ($printsAs): void {
            $formatting = array_diff(Arr::flatten($editor->getToolbarButtons()), ['mergeTags', 'undo', 'redo']);

            foreach ($formatting as $button) {
                expect(array_key_exists($button, $printsAs))->toBeTrue("The toolbar offers {$button}, and nothing here says the letter prints it.");
                expect((new Letter)->page([], '<p>Before</p>'.$printsAs[$button]))->toContain($printsAs[$button]);
            }

            expect($editor->hasFileAttachments())->toBeFalse();
        });
    });
});

it('opens only for somebody holding the letters action over the whole company', function () {
    TenantContext::run($this->vertex, function () {
        $this->actingAs(whoWritesVertexsLetters('meera'));
        expect(Letters::canAccess())->toBeTrue();

        // Farhan clears IT on exits. Writing what a leaver is given over a signature is not
        // part of that.
        $this->actingAs(whoWritesVertexsLetters('farhan'));
        expect(Letters::canAccess())->toBeFalse();

        // The same letter goes to a leaver in every branch, so a grant over one part of the
        // company does not open it.
        $plant = OrgUnit::query()->where('name', 'Nashik plant')->sole();
        $role = Role::query()->create(['key' => 'plant_letters', 'name' => 'Plant letters']);
        $role->permissions()->create(['permission' => Permission::WriteLetters]);
        $role->assignments()->create([
            'user_id' => whoWritesVertexsLetters('farhan')->getKey(),
            'org_unit_id' => $plant->getKey(),
            'includes_descendants' => true,
        ]);
        app(PermissionResolver::class)->forget();

        expect(Letters::canAccess())->toBeFalse();
    });

    $farhan = TenantContext::run($this->vertex, fn () => whoWritesVertexsLetters('farhan'));

    $this->actingAs($farhan)
        ->get('http://'.VertexSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/letters')
        ->assertForbidden();
});

it('puts the letters action on an administrator role that predates it, and on no other role', function () {
    $meera = TenantContext::run($this->vertex, function () {
        Role::query()->get()->each(fn (Role $role) => $role->permissions()->where('permission', Permission::WriteLetters)->delete());

        return whoWritesVertexsLetters('meera');
    });

    (require database_path('migrations/2026_11_25_120221_grant_writing_the_letters.php'))->up();
    app(PermissionResolver::class)->forget();

    TenantContext::run($this->vertex, function () use ($meera) {
        expect(app(PermissionResolver::class)->allowsEverywhere($meera, Permission::WriteLetters))->toBeTrue()
            ->and(Role::query()->whereHas('permissions', fn ($query) => $query->where('permission', Permission::WriteLetters))
                ->pluck('key')->all())->toBe([Role::AdministratorKey]);
    });
});
