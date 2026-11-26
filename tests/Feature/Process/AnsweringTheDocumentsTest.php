<?php

use App\Filament\Resources\Cases\Pages\ViewCase;
use App\Models\CaseEvent;
use App\Models\DocumentLink;
use App\Models\IssuedDocument;
use App\Models\NotificationDelivery;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Process\CaseEngine;
use App\Process\DocumentLinks;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Database\Seeders\VertexSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Module 09 step 4: Neha answers her documents through her link.
|
| Meera issues Neha's letters on her last working day and the exit closes. One email goes to
| Neha's personal address with one link. On its page she presses "Received" for each letter,
| and accepts the statement or refuses it with a reason. Each answer is given once and changes
| nothing about the exit. Meera is told of a refusal, and once everything is answered.
*/

beforeEach(function () {
    $this->seed(VertexSeeder::class);

    $this->vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    Storage::fake(config('startx.documents_disk'));
});

/** Every clearance answered, then Meera issues Neha's letters on her last working day. */
function nehasLettersGoOut(): void
{
    TenantContext::run(Tenant::query()->where('slug', VertexSeeder::Slug)->sole(), function () {
        everyDepartmentClearsNeha();
        test()->travelTo(onNehasLastWorkingDay());

        (new CaseEngine)->decide(nehasExitAtVertex(), 8, 'approved', vertexsOwn('meera'));
    });
}

/**
 * Every link to her documents that reached the mail server, oldest first, as the token in
 * it and the address it went to.
 *
 * @return list<array{0: string, 1: string}>
 */
function linksToNehasDocuments(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()
        ->map(fn ($sent): ?array => preg_match('~/documents/([A-Za-z0-9]{48})~', (string) $sent->getOriginalMessage()->getTextBody(), $found)
            ? [$found[1], $sent->getEnvelope()->getRecipients()[0]->getAddress()]
            : null)
        ->filter()->values()->all();
}

function newestTokenToNehasDocuments(): string
{
    return collect(linksToNehasDocuments())->last()[0];
}

/** One of her documents, read afresh. */
function nehasIssued(string $kind): IssuedDocument
{
    return TenantContext::run(Tenant::query()->where('slug', VertexSeeder::Slug)->sole(), fn (): IssuedDocument => issuedOnNehasExit($kind));
}

function answerNehas(string $token, string $kind, string $answer, ?string $reason = null): mixed
{
    return test()->post(atVertexsAddress('documents/'.$token.'/'.nehasIssued($kind)->getKey()), array_filter([
        'answer' => $answer,
        'reason' => $reason,
    ]));
}

it('sends Neha one email at her personal address, with one link kept only as a hash', function () {
    nehasLettersGoOut();

    $sent = linksToNehasDocuments();

    expect($sent)->toHaveCount(1)
        ->and($sent[0][1])->toBe('neha@personal.example');

    TenantContext::run($this->vertex, function () use ($sent) {
        $link = DocumentLink::query()->sole();

        expect($link->token)->toBe(hash('sha256', $sent[0][0]))
            ->and($link->sent_to)->toBe('neha@personal.example')
            ->and($link->expires_at->equalTo(now()->addHours(StepLink::LastsHours)))->toBeTrue()
            ->and(CaseEvent::query()->where('type', DocumentLinks::SentEvent)->sole()->payload['sent_to'])->toBe('neha@personal.example');
    });
});

it('shows Neha each document, a letter answered only with Received and the statement accepted or refused', function () {
    nehasLettersGoOut();

    $page = $this->get(atVertexsAddress('documents/'.newestTokenToNehasDocuments()))
        ->assertOk()
        ->assertSeeInOrder(['Relieving letter', 'Experience letter', 'Settlement statement'])
        ->getContent();

    expect(substr_count($page, 'value="received"'))->toBe(2)
        ->and(substr_count($page, 'value="accepted"'))->toBe(1)
        ->and(substr_count($page, 'value="refused"'))->toBe(1)
        ->and(substr_count($page, '<textarea'))->toBe(1);
});

it('opens her statement through her link exactly as stored, for no open, and no document of another exit', function () {
    nehasLettersGoOut();
    $token = newestTokenToNehasDocuments();
    $statement = nehasIssued(IssuedDocument::SettlementStatement);

    $opened = $this->get(atVertexsAddress("documents/{$token}/{$statement->getKey()}"))->assertOk();

    expect($opened->streamedContent())->toBe(Storage::disk($statement->disk)->get($statement->path));

    $elsewhere = TenantContext::run($this->vertex, function (): IssuedDocument {
        expect(DocumentLink::query()->sole()->opens)->toBe(0);

        return IssuedDocument::create([
            'case_id' => ProcessCase::factory()->create()->getKey(),
            'kind' => IssuedDocument::RelievingLetter,
            'disk' => config('startx.documents_disk'),
            'path' => 'issued-documents/somebody-else.pdf',
            'sha256' => str_repeat('0', 64),
            'issued_by' => vertexsOwn('meera')->getKey(),
        ]);
    });

    $this->get(atVertexsAddress("documents/{$token}/{$elsewhere->getKey()}"))->assertNotFound();
    $this->post(atVertexsAddress("documents/{$token}/{$elsewhere->getKey()}"), ['answer' => 'received'])->assertNotFound();
});

it('records Received a week after the exit closed, through a fresh link to the address on the record, and changes nothing about the exit', function () {
    nehasLettersGoOut();
    $first = newestTokenToNehasDocuments();

    [$closedAt, $steps] = TenantContext::run($this->vertex, fn (): array => [nehasExitAtVertex()->closed_at, nehasExitAtVertex()->steps()->count()]);

    $this->travel(7)->days();

    $this->get(atVertexsAddress('documents/'.$first))
        ->assertForbidden()
        ->assertSee('Send me a new link');

    // An address typed into the request is never where it goes.
    $this->post(atVertexsAddress('documents/'.$first.'/again'), ['email' => 'somebody@elsewhere.example'])
        ->assertOk()
        ->assertSee('A new link is on its way');

    expect(collect(linksToNehasDocuments())->pluck(1)->unique()->all())->toBe(['neha@personal.example']);

    $fresh = newestTokenToNehasDocuments();

    $this->get(atVertexsAddress('documents/'.$fresh))->assertOk();

    answerNehas($fresh, IssuedDocument::RelievingLetter, 'received')
        ->assertOk()
        ->assertSee('Received on '.now()->timezone($this->vertex->timezone)->format('j F Y'));

    TenantContext::run($this->vertex, function () use ($closedAt, $steps) {
        $exit = nehasExitAtVertex();
        $event = CaseEvent::query()->where('type', DocumentLinks::AnsweredEvent)->sole();

        expect(issuedOnNehasExit(IssuedDocument::RelievingLetter)->answer)->toBe('received')
            ->and($exit->state)->toBe(ProcessCase::Closed)
            ->and($exit->closed_at->equalTo($closedAt))->toBeTrue()
            ->and($exit->steps()->count())->toBe($steps)
            ->and($event->actor_id)->toBeNull()
            ->and($event->payload['answer'])->toBe('received')
            ->and($event->payload['answered_by']['email'])->toBe('neha@personal.example');
    });
});

it('tells Meera at once when Neha refuses the statement, with her reason, and changes nothing about the exit', function () {
    nehasLettersGoOut();

    // Meera takes her emails in one summary at six, and this one still goes at once.
    TenantContext::run($this->vertex, fn () => vertexsOwn('meera')->forceFill(['daily_summary_hour' => 18])->save());

    answerNehas(newestTokenToNehasDocuments(), IssuedDocument::SettlementStatement, 'refused', 'The uniform was returned on my last day.')
        ->assertOk()
        ->assertSee('Refused on')
        ->assertSee('Your reason: The uniform was returned on my last day.');

    TenantContext::run($this->vertex, function () {
        $told = NotificationDelivery::query()->where('template_key', TemplateCatalogue::DocumentRefusedIssuer)->sole();

        expect($told->recipient_user_id)->toBe(vertexsOwn('meera')->getKey())
            ->and($told->status)->toBe(NotificationDelivery::Sent)
            ->and($told->subject)->toContain('Neha Deshpande refused the settlement statement')
            ->and($told->body)->toContain('The reason given was: The uniform was returned on my last day.')
            ->and(NotificationDelivery::query()->where('template_key', TemplateCatalogue::DocumentsAnsweredIssuer)->exists())->toBeFalse()
            ->and(nehasExitAtVertex()->state)->toBe(ProcessCase::Closed)
            ->and(CaseEvent::query()->where('type', DocumentLinks::AnsweredEvent)->sole()->payload['reason'])
            ->toBe('The uniform was returned on my last day.');
    });
});

it('tells Meera once and at once when the last document is answered, naming every answer', function () {
    nehasLettersGoOut();
    $token = newestTokenToNehasDocuments();

    // On a summary at six, and a refusal answered last still reaches her straight away.
    TenantContext::run($this->vertex, fn () => vertexsOwn('meera')->forceFill(['daily_summary_hour' => 18])->save());

    answerNehas($token, IssuedDocument::RelievingLetter, 'received')->assertOk();
    answerNehas($token, IssuedDocument::ExperienceLetter, 'received')->assertOk();
    answerNehas($token, IssuedDocument::SettlementStatement, 'refused', 'The uniform was returned on my last day.')->assertOk();

    TenantContext::run($this->vertex, function () {
        $told = NotificationDelivery::query()->where('template_key', TemplateCatalogue::DocumentsAnsweredIssuer)->sole();

        expect($told->recipient_user_id)->toBe(vertexsOwn('meera')->getKey())
            ->and($told->status)->toBe(NotificationDelivery::Sent)
            ->and($told->body)->toContain('Relieving letter: received on')
            ->and($told->body)->toContain('Experience letter: received on')
            ->and($told->body)->toContain('Settlement statement: refused on')
            ->and($told->body)->toContain('The reason given was: The uniform was returned on my last day.')
            ->and(NotificationDelivery::query()->where('template_key', TemplateCatalogue::DocumentRefusedIssuer)->exists())->toBeFalse();
    });
});

it('refuses a refusal of the statement with a reason under ten characters, keeping what Neha typed', function () {
    nehasLettersGoOut();

    answerNehas(newestTokenToNehasDocuments(), IssuedDocument::SettlementStatement, 'refused', '  Too short  ')
        ->assertUnprocessable()
        ->assertSee('Say why you are refusing the settlement statement, in at least 10 characters.')
        ->assertSee('Too short');

    expect(nehasIssued(IssuedDocument::SettlementStatement)->answer)->toBeNull();
});

it('refuses a reason over its limit on the page, keeping what Neha typed, for no open', function () {
    nehasLettersGoOut();

    // Under the box's limit as the browser counts, over it as sent: each line break goes as two.
    $reason = str_repeat("The uniform was returned.\r\n", 80);

    answerNehas(newestTokenToNehasDocuments(), IssuedDocument::SettlementStatement, 'refused', $reason)
        ->assertUnprocessable()
        ->assertSee('A reason can be at most '.DocumentLinks::ReasonAtMost.' characters long.')
        ->assertSee('The uniform was returned.');

    expect(nehasIssued(IssuedDocument::SettlementStatement)->answer)->toBeNull()
        ->and(TenantContext::run($this->vertex, fn (): int => DocumentLink::query()->sole()->opens))->toBe(0);
});

it('refuses a letter answered any way but Received', function () {
    nehasLettersGoOut();

    answerNehas(newestTokenToNehasDocuments(), IssuedDocument::RelievingLetter, 'accepted')
        ->assertUnprocessable()
        ->assertSee('The relieving letter cannot be answered that way.');

    expect(nehasIssued(IssuedDocument::RelievingLetter)->answer)->toBeNull();
});

it('cannot be replayed: an answer is given once, and an older link can neither answer nor ask again', function () {
    nehasLettersGoOut();
    $first = newestTokenToNehasDocuments();

    answerNehas($first, IssuedDocument::RelievingLetter, 'received')->assertOk();

    answerNehas($first, IssuedDocument::RelievingLetter, 'received')
        ->assertUnprocessable()
        ->assertSee('The relieving letter has already been answered: received on');

    $this->post(atVertexsAddress('documents/'.$first.'/again'))->assertOk();
    $fresh = newestTokenToNehasDocuments();

    answerNehas($fresh, IssuedDocument::RelievingLetter, 'received')->assertUnprocessable();

    // The older link, once a newer one has gone out.
    answerNehas($first, IssuedDocument::ExperienceLetter, 'received')->assertForbidden();
    $this->get(atVertexsAddress('documents/'.$first))->assertForbidden();
    $this->post(atVertexsAddress('documents/'.$first.'/again'))
        ->assertForbidden()
        ->assertSee('A newer link to these documents has already been sent');

    expect(linksToNehasDocuments())->toHaveCount(2);
});

it('stops opening after five opens, and the fifth can still be answered from', function () {
    nehasLettersGoOut();
    $token = newestTokenToNehasDocuments();

    for ($opened = 0; $opened < StepLink::Opens; $opened++) {
        $this->get(atVertexsAddress('documents/'.$token))->assertOk();
    }

    $this->get(atVertexsAddress('documents/'.$token))
        ->assertForbidden()
        ->assertSee('This link no longer opens')
        ->assertSee('Send me a new link');

    answerNehas($token, IssuedDocument::RelievingLetter, 'received')->assertOk();
});

it('stops working after its hours, for the page, the files and the answers', function () {
    nehasLettersGoOut();
    $token = newestTokenToNehasDocuments();
    $letter = nehasIssued(IssuedDocument::RelievingLetter);

    $this->travel(StepLink::LastsHours)->hours();
    $this->travel(1)->minutes();

    $this->get(atVertexsAddress('documents/'.$token))->assertForbidden();
    $this->get(atVertexsAddress("documents/{$token}/{$letter->getKey()}"))->assertForbidden();
    answerNehas($token, IssuedDocument::RelievingLetter, 'received')->assertForbidden();

    expect(nehasIssued(IssuedDocument::RelievingLetter)->answer)->toBeNull();
});

it('opens nothing on the platform\'s own address', function () {
    nehasLettersGoOut();

    $this->get('http://'.config('tenancy.central_domain').'/documents/'.newestTokenToNehasDocuments())
        ->assertForbidden()
        ->assertSee('This link no longer opens');
});

it('lets Meera send Neha a fresh link from the closed exit\'s page, once a day', function () {
    nehasLettersGoOut();
    $first = newestTokenToNehasDocuments();

    TenantContext::run($this->vertex, function () {
        Livewire::actingAs(vertexsOwn('meera'))->test(ViewCase::class, ['record' => nehasExitAtVertex()->getKey()])
            ->callAction('sendAFreshLink')
            ->assertNotified('A fresh link is on its way to Neha Deshpande')
            ->assertSee(vertexsOwn('meera')->name.' sent a fresh link')
            ->assertDontSee('pressed the chase button')
            ->callAction('sendAFreshLink')
            ->assertNotified('Nothing was sent');

        expect(NotificationDelivery::query()->where('template_key', TemplateCatalogue::LinkToTheirDocuments)->whereNotNull('triggered_by')->sole()->triggered_by)
            ->toBe(vertexsOwn('meera')->getKey());
    });

    expect(linksToNehasDocuments())->toHaveCount(2)
        ->and(collect(linksToNehasDocuments())->last()[1])->toBe('neha@personal.example');

    $this->get(atVertexsAddress('documents/'.$first))->assertForbidden();
    $this->get(atVertexsAddress('documents/'.newestTokenToNehasDocuments()))->assertOk();
});

it('offers the fresh link only while a document the reader may open waits for an answer', function () {
    nehasLettersGoOut();
    $token = newestTokenToNehasDocuments();

    answerNehas($token, IssuedDocument::RelievingLetter, 'received')->assertOk();
    answerNehas($token, IssuedDocument::ExperienceLetter, 'received')->assertOk();

    TenantContext::run($this->vertex, function () {
        $exit = ['record' => nehasExitAtVertex()->getKey()];

        // Only the statement waits, which Farhan clearing IT never sees.
        Livewire::actingAs(vertexsOwn('farhan'))->test(ViewCase::class, $exit)->assertActionHidden('sendAFreshLink');
        Livewire::actingAs(vertexsOwn('sanjay'))->test(ViewCase::class, $exit)->assertActionVisible('sendAFreshLink');
    });

    answerNehas($token, IssuedDocument::SettlementStatement, 'accepted')->assertOk();

    TenantContext::run($this->vertex, function () {
        Livewire::actingAs(vertexsOwn('meera'))->test(ViewCase::class, ['record' => nehasExitAtVertex()->getKey()])
            ->assertActionHidden('sendAFreshLink');
    });
});

it('shows on the exit\'s page where each document stands, the statement\'s answer only to whoever may see the money', function () {
    nehasLettersGoOut();
    $token = newestTokenToNehasDocuments();

    answerNehas($token, IssuedDocument::RelievingLetter, 'received')->assertOk();
    answerNehas($token, IssuedDocument::SettlementStatement, 'refused', 'The uniform was returned on my last day.')->assertOk();

    TenantContext::run($this->vertex, function () {
        $exit = ['record' => nehasExitAtVertex()->getKey()];

        Livewire::actingAs(vertexsOwn('sanjay'))->test(ViewCase::class, $exit)
            ->assertSeeInOrder([
                'Where it stands',
                'Relieving letter', 'Received on',
                'Experience letter', 'Waiting for Neha Deshpande',
                'Settlement statement', 'Refused on', 'Why: The uniform was returned on my last day.',
            ])
            ->assertSee('A document you issued was refused')
            ->assertSee('A claim has been raised against you');

        // Nor does the list of emails sent tell Farhan that money is in question.
        Livewire::actingAs(vertexsOwn('farhan'))->test(ViewCase::class, $exit)
            ->assertSee('Received on')
            ->assertDontSee('The uniform was returned on my last day.')
            ->assertSee('A link to the documents issued to them')
            ->assertDontSee('A document you issued was refused')
            ->assertDontSee('A claim has been raised against you');
    });
});

/*
| The record. A refused statement abandons the test's own transaction in Postgres, so each
| expected refusal has a test to itself.
*/

it('lets the database take an answer once and nothing else', function (Closure $write, string $refusal) {
    nehasLettersGoOut();

    TenantContext::run($this->vertex, function () use ($write, $refusal) {
        $letter = issuedOnNehasExit(IssuedDocument::RelievingLetter);
        $statement = issuedOnNehasExit(IssuedDocument::SettlementStatement);

        expect(fn () => $write($letter, $statement))->toThrow(QueryException::class, $refusal);
    });
})->with([
    'a second answer' => [function (IssuedDocument $letter): void {
        $letter->forceFill(['answer' => 'received', 'answered_at' => now()])->save();
        $letter->forceFill(['answered_at' => now()->addDay()])->save();
    }, 'issued_documents keeps what was issued; update refused.'],
    'a letter accepted' => [function (IssuedDocument $letter): void {
        $letter->forceFill(['answer' => 'accepted', 'answered_at' => now()])->save();
    }, 'issued_documents_answer_fits_the_kind'],
    'a refusal with a short reason' => [function (IssuedDocument $letter, IssuedDocument $statement): void {
        $statement->forceFill(['answer' => 'refused', 'reason' => 'Too short', 'answered_at' => now()])->save();
    }, 'issued_documents_answer_fits_the_kind'],
    'a refusal with no reason' => [function (IssuedDocument $letter, IssuedDocument $statement): void {
        $statement->forceFill(['answer' => 'refused', 'answered_at' => now()])->save();
    }, 'issued_documents_answer_fits_the_kind'],
    'a reason with no answer' => [function (IssuedDocument $letter, IssuedDocument $statement): void {
        $statement->forceFill(['reason' => 'The uniform was returned on my last day.'])->save();
    }, 'issued_documents_answer_fits_the_kind'],
    'an answer with no time' => [function (IssuedDocument $letter): void {
        $letter->forceFill(['answer' => 'received'])->save();
    }, 'issued_documents_answer_fits_the_kind'],
    'the file moved while unanswered' => [function (IssuedDocument $letter): void {
        $letter->forceFill(['path' => 'issued-documents/elsewhere.pdf'])->save();
    }, 'issued_documents keeps what was issued; update refused.'],
]);
