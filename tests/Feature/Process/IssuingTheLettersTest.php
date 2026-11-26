<?php

use App\Exceptions\ProcessRefused;
use App\Filament\Pages\MyQueue;
use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\FormField;
use App\Models\IssuedDocument;
use App\Models\LetterSetup;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Process\CaseHistory;
use App\Process\Letter;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\VertexSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Module 09 step 3: the letters go out at the exit.
|
| Neha is leaving Vertex. Her seven clearances are answered, with money claimed from her and
| owed to her, and then Meera issues her letters: the relieving letter, the experience letter
| and the settlement statement, each drawn, stored and recorded in the same save that
| finishes the step. Not before her last working day, and not with nowhere to send them.
|
| A letter opens for whoever may see Neha, the statement only for whoever may see the money:
| Farhan, clearing IT, reads her letters and never her statement; Sanjay, in finance, reads both.
*/

beforeEach(function () {
    $this->seed(VertexSeeder::class);

    $this->vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    Storage::fake(config('startx.documents_disk'));
});

function vertexsOwn(string $first): User
{
    return User::query()->where('work_email', $first.'@vertex.test')->sole();
}

function nehasExitAtVertex(): ProcessCase
{
    return ProcessCase::query()->where('subject_user_id', vertexsOwn('neha')->getKey())->sole();
}

/**
 * Every one of Vertex's seven clearances answered, each by whoever holds it, with the money
 * the statement test in module 08 worked out by hand: ₹2,520.09 claimed, ₹16,235.31 owed.
 */
function everyDepartmentClearsNeha(): ProcessCase
{
    $holders = [1 => 'meera', 2 => 'meera', 3 => 'sanjay', 4 => 'farhan', 5 => 'meera', 6 => 'sanjay', 7 => 'meera'];
    $money = [
        6 => [
            'finance_recovery_amount' => 2000.10,
            'finance_recovery_reason' => 'Salary advance',
            'finance_payable_amount' => 15000.75,
            'finance_payable_reason' => 'Bonus',
        ],
        7 => [
            'notice_recovery_amount' => 500,
            'hr_other_recovery_amount' => 19.99,
            'hr_other_reason' => 'Uniform',
            'encashment_payable_amount' => 1234.56,
        ],
    ];

    foreach ($holders as $sequence => $first) {
        $exit = nehasExitAtVertex();
        $required = $exit->template->steps->firstWhere('sequence', $sequence)->form->fields
            ->where('required', true)
            ->mapWithKeys(fn (FormField $field): array => [$field->key => true])
            ->all();

        (new CaseEngine)->decide($exit, $sequence, 'approved', vertexsOwn($first), [...$required, ...($money[$sequence] ?? [])]);
    }

    return nehasExitAtVertex();
}

/** Ten in the morning on Neha's last working day, on Vertex's own clock. */
function onNehasLastWorkingDay(): CarbonImmutable
{
    return CarbonImmutable::parse(nehasExitAtVertex()->statutory_from->toDateString().' 10:00', Tenant::query()->where('slug', VertexSeeder::Slug)->sole()->timezone);
}

/** An address on Vertex's own subdomain. */
function atVertexsAddress(string $path): string
{
    return 'http://'.VertexSeeder::Slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
}

function issuedOnNehasExit(string $kind): IssuedDocument
{
    return IssuedDocument::query()->where('case_id', nehasExitAtVertex()->getKey())->where('kind', $kind)->sole();
}

/** What a reader of a page sees, with the markup taken away and every entity read. */
function asReadByNeha(string $page): string
{
    return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($page), ENT_QUOTES | ENT_HTML5));
}

it('issues Neha\'s relieving letter, experience letter and statement when Meera completes the step, and finishes the exit', function () {
    TenantContext::run($this->vertex, function () {
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());
        $meera = vertexsOwn('meera');

        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', $meera);

        $issued = IssuedDocument::query()->where('case_id', nehasExitAtVertex()->getKey())->orderBy('id')->get();
        $disk = Storage::disk(config('startx.documents_disk'));

        expect($issued->pluck('kind')->all())->toBe([
            IssuedDocument::RelievingLetter,
            IssuedDocument::ExperienceLetter,
            IssuedDocument::SettlementStatement,
        ])
            ->and($issued->pluck('issued_by')->unique()->all())->toBe([$meera->getKey()])
            ->and($issued->every(fn (IssuedDocument $document): bool => str_starts_with((string) $disk->get($document->path), '%PDF-')
                && hash('sha256', (string) $disk->get($document->path)) === $document->sha256))->toBeTrue()
            ->and(nehasExitAtVertex()->state)->toBe(ProcessCase::Closed);
    });
});

it('draws the statement from the rows and words of the exit\'s page, to the paisa', function () {
    TenantContext::run($this->vertex, function () {
        $exit = everyDepartmentClearsNeha();

        // By hand, as module 08's statement test: claimed 500 + 19.99 + 2,000.10 = 2,520.09;
        // owed 1,234.56 + 15,000.75 = 16,235.31; so 13,715.22 is owed to Neha.
        $figures = [
            'Claimed from Neha Deshpande',
            'Finance clearance', 'Salary advance', '₹2,000.10',
            'HR clearance', 'Notice period shortfall to recover', '₹500.00',
            'HR clearance', 'Uniform', '₹19.99',
            'Owed to Neha Deshpande',
            'Finance clearance', 'Bonus', '₹15,000.75',
            'HR clearance', 'Leave encashment payable to them', '₹1,234.56',
            'Claimed from Neha Deshpande', '₹2,520.09',
            'Owed to Neha Deshpande', '₹16,235.31',
            'Net of claims', '₹13,715.22 owed to Neha Deshpande',
        ];

        $statement = asReadByNeha((new Letter)->statementPage($exit));
        $at = 0;

        foreach ($figures as $figure) {
            $found = strpos($statement, $figure, $at);
            expect($found)->not->toBeFalse("The statement does not say [{$figure}] where the page does.");
            $at = $found + strlen($figure);
        }

        // And the page says the same, in the same order, to Sanjay in finance.
        Livewire::actingAs(vertexsOwn('sanjay'))->test(ViewCase::class, ['record' => $exit->getKey()])
            ->assertSeeInOrder($figures);
    });
});

it('refuses the letters before Neha\'s last working day, and her card says when instead of offering the button', function () {
    TenantContext::run($this->vertex, function () {
        $exit = everyDepartmentClearsNeha();
        $day = $exit->statutory_from->format('j F Y');
        $refusal = "Neha Deshpande's letters can be issued from {$day}, the last working day.";

        expect(fn () => (new CaseEngine)->decide($exit, 8, 'approved', vertexsOwn('meera')))
            ->toThrow(ProcessRefused::class, $refusal)
            ->and(IssuedDocument::query()->count())->toBe(0)
            ->and(nehasExitAtVertex()->state)->toBe(ProcessCase::Open);

        Livewire::actingAs(vertexsOwn('meera'))->test(MyQueue::class)
            ->assertSee($refusal)
            ->assertDontSee('Issue the letters');

        $this->travelTo(onNehasLastWorkingDay());

        Livewire::actingAs(vertexsOwn('meera'))->test(MyQueue::class)
            ->assertDontSee($refusal)
            ->assertSee('Issue the letters');
    });
});

it('refuses the letters to a leaver with no personal email, in the words the answer link uses', function () {
    TenantContext::run($this->vertex, function () {
        $exit = everyDepartmentClearsNeha();
        vertexsOwn('neha')->forceFill(['personal_email' => null])->save();
        $this->travelTo(onNehasLastWorkingDay());

        expect(fn () => (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera')))
            ->toThrow(ProcessRefused::class, "[Issue the leaver's letters] cannot be done: there is no personal email address recorded for Neha Deshpande to send the letters to.")
            ->and(IssuedDocument::query()->count())->toBe(0)
            ->and(nehasExitAtVertex()->state)->toBe(ProcessCase::Open);
    });
});

it('keeps the step open and says why when a picture saved on the Letters screen cannot be read', function () {
    TenantContext::run($this->vertex, function () {
        LetterSetup::query()->create(['signature' => 'letter-pictures/'.$this->vertex->getKey().'/gone.png']);
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());

        expect(fn () => (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera')))
            ->toThrow(ProcessRefused::class, 'The signature saved on the Letters screen cannot be read, so no letter was issued.')
            ->and(IssuedDocument::query()->count())->toBe(0)
            ->and(Storage::disk(config('startx.documents_disk'))->allFiles('issued-documents'))->toBe([])
            ->and(nehasExitAtVertex()->state)->toBe(ProcessCase::Open)
            ->and(nehasExitAtVertex()->liveSteps->firstWhere('sequence', 8)?->acted_at)->toBeNull();
    });
});

it('opens a letter for whoever may see Neha, and the statement only for whoever may see the money', function () {
    TenantContext::run($this->vertex, function () {
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());
        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera'));

        $letter = atVertexsAddress('issued-documents/'.issuedOnNehasExit(IssuedDocument::RelievingLetter)->getKey());
        $statement = atVertexsAddress('issued-documents/'.issuedOnNehasExit(IssuedDocument::SettlementStatement)->getKey());

        // Each request ends with no company in scope, so everybody is found first.
        [$farhan, $sanjay, $meera, $neha] = array_map(vertexsOwn(...), ['farhan', 'sanjay', 'meera', 'neha']);

        $opened = $this->actingAs($farhan)->get($letter);
        $opened->assertOk();

        expect($opened->headers->get('content-type'))->toBe('application/pdf')
            ->and($opened->headers->get('content-disposition'))->toStartWith('inline')
            ->and($opened->headers->get('x-content-type-options'))->toBe('nosniff');

        $this->actingAs($farhan)->get($statement)->assertNotFound();
        $this->actingAs($sanjay)->get($statement)->assertOk();
        $this->actingAs($meera)->get($statement)->assertOk();

        // Neha opens hers through her link (step 4), never by signing in to her own exit.
        $this->actingAs($neha)->get($letter)->assertNotFound();
    });
});

it('opens the letter of a leaver whose name carries a slash', function () {
    TenantContext::run($this->vertex, function () {
        vertexsOwn('neha')->update(['last_name' => 'd/o Deshpande']);
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());
        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera'));

        $letter = atVertexsAddress('issued-documents/'.issuedOnNehasExit(IssuedDocument::RelievingLetter)->getKey());
        $meera = vertexsOwn('meera');

        expect($this->actingAs($meera)->get($letter)->assertOk()->headers->get('content-disposition'))
            ->toContain('Neha d-o Deshpande.pdf');
    });
});

it('opens nothing to somebody signed in at another company, or to nobody signed in', function () {
    $elsewhere = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian-letters']);
    $atMeridian = TenantContext::run($elsewhere, fn () => User::factory()->named('Sunil Rao')->create());

    TenantContext::run($this->vertex, function () use ($atMeridian) {
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());
        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera'));
        $letter = atVertexsAddress('issued-documents/'.issuedOnNehasExit(IssuedDocument::RelievingLetter)->getKey());

        // The ordinary case for an address that has been forwarded or pasted somewhere.
        $this->get($letter)->assertRedirect();

        // Somebody at another company, signed in, at Vertex's own address.
        $this->actingAs($atMeridian)->get($letter)->assertNotFound();
    });
});

it('lists the documents on the exit\'s page, the statement only to whoever may see the money', function () {
    TenantContext::run($this->vertex, function () {
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());
        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera'));
        $exit = nehasExitAtVertex();
        $statement = 'href="'.route('issued-document', issuedOnNehasExit(IssuedDocument::SettlementStatement)).'"';

        Livewire::actingAs(vertexsOwn('sanjay'))->test(ViewCase::class, ['record' => $exit->getKey()])
            ->assertSeeInOrder(['Documents issued', 'Relieving letter', 'Meera Joshi', 'Experience letter', 'Settlement statement'])
            ->assertSee($statement, escape: false);

        Livewire::actingAs(vertexsOwn('farhan'))->test(ViewCase::class, ['record' => $exit->getKey()])
            ->assertSeeInOrder(['Documents issued', 'Relieving letter', 'Experience letter'])
            ->assertDontSee('Settlement statement')
            ->assertDontSee($statement, escape: false);
    });
});

it('says in the exit\'s history that Meera issued the letters, not that she approved them', function () {
    TenantContext::run($this->vertex, function () {
        everyDepartmentClearsNeha();
        $this->travelTo(onNehasLastWorkingDay());
        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera'));

        $line = collect((new CaseHistory)->stepByStep(nehasExitAtVertex()))->firstWhere('sequence', 8);

        expect($line['name'])->toBe('Issue the leaver\'s letters')
            ->and($line['said'])->toStartWith('Issued by Meera Joshi on ');
    });
});

it('refuses a document of a kind nobody issues, at the database', function () {
    TenantContext::run($this->vertex, function () {
        DB::table('issued_documents')->insert([
            'tenant_id' => $this->vertex->getKey(),
            'case_id' => nehasExitAtVertex()->getKey(),
            'kind' => 'offer_letter',
            'disk' => 'local',
            'path' => 'issued-documents/x.pdf',
            'sha256' => str_repeat('0', 64),
            'issued_by' => vertexsOwn('meera')->getKey(),
        ]);
    });
})->throws(QueryException::class, 'issued_documents_kind_known');
