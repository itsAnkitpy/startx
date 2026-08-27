<?php

use App\Filament\Pages\MyQueue;
use App\Models\Designation;
use App\Models\EmploymentRecord;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Process\CaseEngine;
use App\Process\SubjectPanel;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| The panel above every step's form, and the seven real clearance forms.
|
| Two halves of one claim. The panel says who the case is about as the case froze them,
| so a decision taken under an old job title is still read under it a year later. The
| seven forms are the old tool's own department clearances, seeded as rows, and the code
| that seeds them does not know what any of them asks.
*/

/** Somebody at Vertex Foods, by first name. */
function atVertexCalled(string $first): User
{
    return User::query()->where('work_email', $first.'@vertex.test')->sole();
}

it('shows the person as the case froze them, not as they are now', function () {
    $meridian = Tenant::query()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian-frozen']);

    TenantContext::run($meridian, function () {
        $shimla = OrgUnit::factory()->ofType('company')->create(['name' => 'Shimla branch']);
        $pune = OrgUnit::factory()->ofType('company')->create(['name' => 'Pune branch']);
        $officer = Designation::factory()->named('Operations Officer')->create();
        $head = Designation::factory()->named('Regional Head')->create();

        $rakesh = User::factory()->named('Rakesh Menon')->create();
        $chandni = User::factory()->named('Chandni Verma')->create();
        $anjali = User::factory()->named('Anjali Rao')->create();

        EmploymentRecord::factory()->forPerson($anjali)->in($shimla)
            ->designated($officer)->reportingTo($rakesh)
            ->effective('2024-04-01')->create();

        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance')->create();
        $exit->publish();

        $case = (new CaseEngine)->open($exit, $anjali, $chandni);

        // A year passes. Anjali is promoted, moved to Pune and now reports to Chandni,
        // and the exit she was cleared under has been closed all along.
        EmploymentRecord::query()->where('user_id', $anjali->getKey())
            ->whereNull('effective_to')->sole()
            ->update(['effective_to' => '2026-03-31']);

        EmploymentRecord::factory()->forPerson($anjali)->in($pune)
            ->designated($head)->reportingTo($chandni)
            ->effective('2026-04-01')->create();

        // The list she is renamed on too, because freezing which row was chosen is not
        // the same as freezing what that row said.
        $officer->update(['name' => 'Ops Officer (Grade II)']);

        $panel = (new SubjectPanel)->of($case->fresh());

        expect($panel['facts']['Designation'])->toBe('Operations Officer')
            ->and($panel['facts']['Department'])->toBe('Shimla branch')
            ->and($panel['facts']['Reports to'])->toBe('Rakesh Menon')
            ->and($panel['who'])->toContain('Anjali Rao');

        // And what she is today, so the test is about the difference rather than about
        // nothing having happened.
        expect($anjali->fresh()->currentEmployment->orgUnit->name)->toBe('Pune branch')
            ->and($anjali->fresh()->currentEmployment->recorded_designation_name)->toBe('Regional Head');
    });
});

it('says so plainly when a case is about nobody', function () {
    $tenant = Tenant::query()->create(['name' => 'Vertex Foods', 'slug' => 'vertex-nobody']);

    TenantContext::run($tenant, function () {
        $request = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();
        ProcessStep::factory()->of($request)->at(1, 1)->named('Director approval')->create();
        $request->publish();

        $panel = (new SubjectPanel)->of((new CaseEngine)->open($request));

        expect($panel['facts'])->toBe([])
            ->and($panel['instead'])->toContain('not about a person');
    });
});

it('puts the details above the form on the queue screen', function () {
    $this->seed(MeridianSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();

    TenantContext::run($meridian, fn () => Livewire::actingAs(
        User::query()->where('work_email', 'rakesh@meridian.test')->sole()
    )->test(MyQueue::class)
        ->assertOk()
        ->assertSee('Who this is about')
        ->assertSee('Operations Officer')
        ->assertSee('Shimla branch')
        ->assertSee('Shimla office')
        ->assertSee('Rakesh Menon')
        // The claim of the panel, on the panel.
        ->assertSee('not as they are today')
        // A fact nobody recorded says so rather than showing a blank.
        ->assertSee('Not recorded'));
});

it('draws every panel on the queue without a query per card', function () {
    $this->seed(VertexSeeder::class);

    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    TenantContext::run($vertex, function () {
        $meera = atVertexCalled('meera');
        $this->actingAs($meera);

        // The list on its own, then the same list with all four panels read off it. The
        // panel's facts are loaded for the whole list at once, so a card costs the same
        // whether the panel is drawn or not — four cards must not become twelve reads.
        DB::enableQueryLog();
        (new MyQueue)->queue();
        $listOnly = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $panels = (new MyQueue)->queue()->map(fn ($waiting) => (new SubjectPanel)->of($waiting->case));
        $withPanels = count(DB::getQueryLog());
        DB::flushQueryLog();

        expect($panels)->toHaveCount(4)
            ->and($panels->first()['facts']['Department'])->toBe('Nashik plant')
            ->and($withPanels)->toBe($listOnly);
    });
});

it('seeds the old tool\'s seven department clearances with no code between them', function () {
    $this->seed(VertexSeeder::class);

    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    TenantContext::run($vertex, function () {
        $exit = ProcessTemplate::query()->where('key', 'exit')->sole();

        expect($exit->status)->toBe(ProcessTemplate::Published)
            ->and($exit->steps()->count())->toBe(7)
            // All seven clear at once, which is how the old tool ran them: every
            // department had its own pending list and none waited on another.
            ->and($exit->steps()->pluck('group_no')->unique()->all())->toBe([1]);

        // Forty-nine questions the old tool carried as columns on one table.
        expect(FormDefinition::query()->where('status', FormDefinition::Published)->count())->toBe(7)
            ->and(FormField::query()->count())->toBe(49);

        // Four of the twelve types cover all seven forms. The type list was never the
        // thing that made the old tool add a column every time a client asked for one.
        expect(FormField::query()->distinct()->pluck('type')->sort()->values()->all())
            ->toBe([FormField::Boolean, FormField::File, FormField::Money, FormField::Textarea]);

        // A question that stops the clearance, and a question that only appears once an
        // earlier answer calls for it, both survive the seeding.
        $it = FormDefinition::query()->where('key', 'it_noc')->where('status', FormDefinition::Published)->sole();

        expect($it->fields()->where('key', 'mailbox_switched_off')->value('required'))->toBeTrue()
            ->and($it->fields()->where('key', 'mailbox_reason')->value('visible_if'))
            ->toEqual([[['field' => 'mailbox_switched_off', 'operator' => '=', 'value' => false]]]);
    });
});

it('puts four different clearance forms in front of one person', function () {
    $this->seed(VertexSeeder::class);

    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    // Meera holds HR, admin and the booking desk, and is also Neha's manager. One sign-in
    // shows four clearances of the same exit, each asking something else entirely.
    TenantContext::run($vertex, fn () => Livewire::actingAs(atVertexCalled('meera'))->test(MyQueue::class)
        ->assertOk()
        ->assertSee('Neha Deshpande')
        ->assertSee('Reporting manager clearance')
        ->assertSee('Handover completed')
        ->assertSee('HR clearance')
        ->assertSee('Leave encashment payable to them')
        ->assertSee('Admin clearance')
        ->assertSee('Assets handed back')
        ->assertSee('Booking system clearance')
        ->assertSee('Booking system account switched off')
        // Not IT's or finance's, which are somebody else's.
        ->assertDontSee('Work mailbox switched off')
        ->assertDontSee('Imprest card recovered')
        // And the panel above all four, with a real legal deadline on it.
        ->assertSee('Who this is about')
        ->assertSee('Quality Executive')
        ->assertSee('Nashik plant'));
});

it('gives the two companies completely different exits and no shared schema', function () {
    $this->seed(MeridianSeeder::class);
    $this->seed(VertexSeeder::class);

    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    $meridianAsks = TenantContext::run($meridian, fn () => FormField::query()->pluck('key')->sort()->values()->all());
    $vertexAsks = TenantContext::run($vertex, fn () => FormField::query()->pluck('key')->sort()->values()->all());

    // Two exits with nothing in common, running on the same two tables. Nothing forces
    // that — two clients may well ask the same question — but these two do not, and no
    // migration stands between them.
    expect(array_intersect($meridianAsks, $vertexAsks))->toBe([])
        ->and($meridianAsks)->not->toBeEmpty()
        ->and($vertexAsks)->not->toBeEmpty();

    // Neither company can see the other's questions, which is the wall rather than this
    // module — checked here because two companies is the first time it can be.
    expect(TenantContext::run($vertex, fn () => FormField::query()->where('key', 'id_card_photo')->count()))->toBe(0);
});

it('counts every clearance step as waiting on the day the exit opens', function () {
    $this->seed(VertexSeeder::class);

    $vertex = Tenant::query()->where('slug', VertexSeeder::Slug)->sole();

    TenantContext::run($vertex, function () {
        $case = ProcessCase::query()->sole();

        // A legal deadline the panel can actually show, counted from Neha's last working
        // day rather than left blank the way Meridian's cases are.
        expect($case->statutory_due_at)->not->toBeNull();

        $panel = (new SubjectPanel)->of($case);

        expect($panel['facts']['Legal deadline'])->toBe($case->statutory_due_at->format('j F Y'))
            ->and($panel['facts']['Last working day'])->not->toBe('Not recorded');
    });
});
