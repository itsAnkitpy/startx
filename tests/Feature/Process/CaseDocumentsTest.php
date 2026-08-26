<?php

use App\Filament\Pages\MyQueue;
use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseDocuments;
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Opening a document that was attached to a step.
|
| The claim is one sentence: a clearance can be verified rather than taken on trust —
| finance opens the photograph HR attached instead of approving on the word that the card
| came back — and nobody else can open it at all.
|
| Everything here runs against the demo company as it is actually seeded, because who may
| open a document is a question about who holds what, and a fixture built to suit the
| answer would prove nothing.
*/

beforeEach(function () {
    Storage::fake('local');

    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody at the demo company, by their first name. */
function meridiansStaffCalled(string $first): User
{
    return User::query()->where('work_email', strtolower($first).'@meridian.test')->sole();
}

/** An address on the demo company's own subdomain. */
function openingAtMeridian(string $path): string
{
    return 'http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
}

/**
 * HR clears Anjali's exit and attaches a photograph of the returned card, which is what
 * every question below is asked about.
 */
function anjalisExitWithHrsPhotograph(string $called = 'anjali-card.pdf', string $type = 'application/pdf'): ProcessCase
{
    $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

    (new CaseEngine)->decide($anjalis, 1, 'approved', meridiansStaffCalled('rakesh'), [
        'id_card_returned' => '1',
        'id_card_photo' => UploadedFile::fake()->create($called, 40, $type),
    ]);

    return $anjalis;
}

it('lets whoever holds a later step open what an earlier one attached', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = anjalisExitWithHrsPhotograph();

        // Chandni holds the finance clearance, which is the step after HR's. Verifying a
        // clearance is exactly this: reading what the step before you filed, rather than
        // approving on the word that it happened.
        $chandni = meridiansStaffCalled('chandni');

        Livewire::actingAs($chandni)->test(MyQueue::class)
            ->assertSee('Documents on this case')
            ->assertSee('HR clearance — Photo or scan of the returned card')
            ->assertSee('anjali-card.pdf')
            ->assertSee(
                'href="/cases/'.$anjalis->getKey().'/documents/1/id_card_photo" target="_blank"',
                escape: false,
            );

        $opened = $this->actingAs($chandni)
            ->get(openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/id_card_photo'));

        $opened->assertOk();

        // Shown in the browser rather than saved, because that is what somebody checking a
        // scan wants — and as the kind it was recorded as, never a kind guessed now from
        // the contents of a file an employee chose.
        expect($opened->headers->get('content-type'))->toStartWith('application/pdf')
            ->and($opened->headers->get('content-disposition'))->toStartWith('inline')
            ->and($opened->headers->get('x-content-type-options'))->toBe('nosniff');
    });
});

it('still opens a document to whoever attached it, once the case has moved on', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = anjalisExitWithHrsPhotograph();

        // Rakesh's step is done and gone from his queue. The evidence he filed stays
        // readable to him, which is most of the reason for keeping it.
        $this->actingAs(meridiansStaffCalled('rakesh'))
            ->get(openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/id_card_photo'))
            ->assertOk();
    });
});

it('refuses a document to anybody with no business with the case', function () {
    $elsewhere = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex-docs']);

    $atVertex = TenantContext::run($elsewhere, fn () => User::factory()->named('Sunil Rao')->create());

    TenantContext::run($this->meridian, function () use ($atVertex) {
        $anjalis = anjalisExitWithHrsPhotograph();

        $at = openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/id_card_photo');

        // Deepak works at the same company and holds no step of this exit. An exit
        // clearance document is about a named person and is not company reading.
        $this->actingAs(meridiansStaffCalled('deepak'))->get($at)->assertNotFound();

        // Somebody at another company, signed in, at this company's own address.
        $this->actingAs($atVertex)->get($at)->assertNotFound();
    });
});

it('opens nothing at all to somebody who is not signed in', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = anjalisExitWithHrsPhotograph();

        // The ordinary case for an address that has been forwarded or pasted somewhere.
        $this->get(openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/id_card_photo'))
            ->assertRedirect();
    });
});

it('answers not found for anything on that case that is not a document', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = anjalisExitWithHrsPhotograph();

        $chandni = meridiansStaffCalled('chandni');
        $at = 'cases/'.$anjalis->getKey().'/documents/';

        // Told apart, this address becomes a way of asking which exits exist and what they
        // collected, one guess at a time. So every way of being wrong gets one answer: a
        // question that holds typed words, a question no form has, and a step nobody has
        // answered yet.
        foreach (['1/id_card_returned', '1/made_up_question', '2/imprest_card_returned'] as $wrong) {
            $this->actingAs($chandni)->get(openingAtMeridian($at.$wrong))->assertNotFound();
        }

        // And a case that is not there at all reads the same.
        $this->actingAs($chandni)
            ->get(openingAtMeridian('cases/99999/documents/1/id_card_photo'))
            ->assertNotFound();
    });
});

it('hands a Word document over to be saved rather than shown', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = anjalisExitWithHrsPhotograph(
            'handover.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        // A browser cannot put one on the screen, so offering to is a link that appears to
        // do nothing. It saves instead, under the name the person gave it rather than the
        // random one it is stored under.
        $this->actingAs(meridiansStaffCalled('chandni'))
            ->get(openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/id_card_photo'))
            ->assertDownload('handover.docx');
    });
});

it('reads a document out of the answers, with the words the client asked the question in', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = anjalisExitWithHrsPhotograph();

        $found = (new CaseDocuments)->on($anjalis->refresh());

        // Where it was attached travels with it. A scan named after somebody's phone tells
        // whoever is verifying nothing about which question it answers.
        expect($found)->toHaveCount(1)
            ->and($found[0]['step'])->toBe('HR clearance')
            ->and($found[0]['label'])->toBe('Photo or scan of the returned card')
            ->and($found[0]['sequence'])->toBe(1)
            ->and($found[0]['name'])->toBe('anjali-card.pdf');
    });
});

it('opens nothing written by hand into a box that asks for words', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // HR's card also has a box for anything they want on the record. What makes
        // something a document is the question the client asked, never the shape of what
        // is stored under it — read the other way round, this is somebody naming a file
        // already on our disk as their own clearance evidence and then opening it.
        (new CaseEngine)->decide($anjalis, 1, 'approved', meridiansStaffCalled('rakesh'), [
            'id_card_returned' => '0',
            'remarks' => ['disk' => 'local', 'path' => 'case-documents/1/somebody-elses.pdf'],
        ]);

        expect((new CaseDocuments)->on($anjalis->refresh()))->toBeEmpty();

        $this->actingAs(meridiansStaffCalled('chandni'))
            ->get(openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/remarks'))
            ->assertNotFound();
    });
});

it('shows the answer that counts at a step, not one a send-back replaced', function () {
    TenantContext::run($this->meridian, function () {
        $anjalis = ProcessCase::query()->whereRelation('subject', 'first_name', 'Anjali')->sole();

        // An earlier attempt at HR's clearance, left behind when the case was sent back to
        // it. The photograph on it stopped being the answer the moment it was replaced.
        $replaced = CaseStep::create([
            'case_id' => $anjalis->getKey(),
            'sequence' => 1,
            'assignee_id' => meridiansStaffCalled('rakesh')->getKey(),
            'outcome' => 'approved',
            'acted_at' => now()->subDay(),
            'payload' => ['id_card_returned' => '1', 'id_card_photo' => [
                'disk' => 'local',
                'path' => 'case-documents/1/replaced.pdf',
                'name' => 'the-wrong-card.pdf',
                'size' => 1024,
                'type' => 'application/pdf',
            ]],
        ]);

        $replaced->superseded_at = now();
        $replaced->save();

        anjalisExitWithHrsPhotograph();

        // One link, and the one behind it. Two identical links would leave whoever is
        // verifying to guess which photograph is the answer, and the address would open
        // whichever row the database happened to hand back first.
        $found = (new CaseDocuments)->on($anjalis->refresh());

        expect($found)->toHaveCount(1)
            ->and($found[0]['name'])->toBe('anjali-card.pdf');

        $opened = $this->actingAs(meridiansStaffCalled('chandni'))
            ->get(openingAtMeridian('cases/'.$anjalis->getKey().'/documents/1/id_card_photo'));

        $opened->assertOk();

        expect($opened->headers->get('content-disposition'))->toContain('anjali-card.pdf');
    });
});
