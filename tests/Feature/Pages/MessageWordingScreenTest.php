<?php

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| The screen a client reads and rewords the messages this product sends on.
|
| What is being checked beyond the rows appearing: the two things this screen deliberately
| cannot do. A client cannot invent a message, because a message nothing sends is words
| nobody reads, and cannot remove one, because a step sends a message by naming it and a
| client with no words for it is a step that silently sends nothing.
|
| It runs against the demo company as seeded, so what is checked is the screen Ankit opens.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function whoRewordsMeridiansMessages(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

it('lists every message the company sends, saying who reads each one', function () {
    TenantContext::run($this->meridian, function () {
        // In the catalogue's own order, which reads down the page by who receives each
        // message. Asserted in order because the alternative — the message's own key,
        // alphabetically — put "your case has been completed" above "your case has been
        // started" on a screen where that word is never shown.
        $inTheOrderTheyRead = collect(array_keys(TemplateCatalogue::definitions()))
            ->map(fn (string $key): NotificationTemplate => NotificationTemplate::query()->where('key', $key)->sole());

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))->test(ListNotificationTemplates::class)
            ->assertOk()
            ->assertCanSeeTableRecords($inTheOrderTheyRead, inOrder: true)
            ->assertSee('A reminder that this is waiting on you')
            ->assertSee('A claim has been raised against you')
            // The two questions somebody opening this screen actually has, answered on
            // the row rather than only inside it.
            ->assertSee('Whoever is holding the work today, including anybody covering')
            ->assertSee('Sent when: a department claims money against them');
    });
});

it('says which messages the company has reworded and which are still in our words', function () {
    TenantContext::run($this->meridian, function () {
        // Meridian has rewritten the chase-up in the demo and nothing else, so the list
        // has to separate the two on sight.
        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))->test(ListNotificationTemplates::class)
            ->assertOk()
            ->assertSee('Second ask:')
            ->assertSee('Yours')
            ->assertSee('Ours');

        $reminder = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepReminderHolder)->sole();

        expect($reminder->reworded)->toBeTrue()
            ->and(NotificationTemplate::query()
                ->where('key', TemplateCatalogue::CaseOpenedSubject)->sole()->reworded)
            ->toBeFalse();
    });
});

it('rewords a message, and the new words are what the company holds afterwards', function () {
    TenantContext::run($this->meridian, function () {
        $opening = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::CaseOpenedSubject)->sole();

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $opening->getKey()])
            ->assertOk()
            // The tokens this message may use are named on the screen, because a name
            // written any other way is left out of what goes out.
            ->assertSee('{{ person.name }}')
            ->fillForm([
                'subject' => 'Meridian has started your {{ process.name }}',
                'body' => "Dear {{ person.name }},\n\nWe have started your {{ process.name }}.\n\nMeridian Logistics",
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $opening->fresh();

        expect($saved->subject)->toBe('Meridian has started your {{ process.name }}')
            ->and($saved->reworded)->toBeTrue()
            // Which message these words belong to is untouched, so nothing has quietly
            // become the wording of a different message.
            ->and($saved->key)->toBe(TemplateCatalogue::CaseOpenedSubject);
    });
});

it('refuses a message with no subject line and one with nothing in it', function () {
    TenantContext::run($this->meridian, function () {
        $opening = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::CaseOpenedSubject)->sole();

        // Both would otherwise reach a client as an error page from the database's own
        // refusal, rather than in our words under the box they typed in.
        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $opening->getKey()])
            ->fillForm(['subject' => '', 'body' => 'Something.'])
            ->call('save')
            ->assertHasFormErrors(['subject' => 'A message needs a subject line.']);

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $opening->getKey()])
            ->fillForm(['subject' => 'Something', 'body' => ''])
            ->call('save')
            ->assertHasFormErrors(['body' => 'A message needs something in it.']);

        expect($opening->fresh()->reworded)->toBeFalse();
    });
});

it('puts our wording back for a company that wants out of its own version', function () {
    TenantContext::run($this->meridian, function () {
        $reminder = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepReminderHolder)->sole();

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $reminder->getKey()])
            ->callAction('putOurWordingBack');

        $back = $reminder->fresh();

        expect($back->subject)
            ->toBe(TemplateCatalogue::definitionOf(TemplateCatalogue::StepReminderHolder)['subject'])
            ->and($back->reworded)->toBeFalse();

        // And the way out is not offered to a company that never went in, because a
        // control that can only do nothing teaches somebody not to trust the screen.
        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $back->getKey()])
            ->assertActionHidden('putOurWordingBack');
    });
});

it('offers no way to add a message and no way to remove one', function () {
    TenantContext::run($this->meridian, function () {
        $opening = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::CaseOpenedSubject)->sole();

        expect(NotificationTemplateResource::canCreate())->toBeFalse();

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))->test(ListNotificationTemplates::class)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('delete')->table($opening));

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $opening->getKey()])
            ->assertActionDoesNotExist('delete');
    });
});

it('keeps the screen shut to somebody without the action that opens it', function () {
    TenantContext::run($this->meridian, function () {
        // Rakesh runs hiring and exits over Shimla. Writing what a leaver is told about
        // money claimed against them is not part of that, which is the whole reason this
        // action is its own rather than folded into the company settings.
        $this->actingAs(whoRewordsMeridiansMessages('rakesh'));
        expect(NotificationTemplateResource::canViewAny())->toBeFalse();

        $this->actingAs(whoRewordsMeridiansMessages('chandni'));
        expect(NotificationTemplateResource::canViewAny())->toBeTrue();
    });

    $anjali = TenantContext::run($this->meridian, fn () => whoRewordsMeridiansMessages('anjali'));

    // And Anjali, who holds no role at all, is refused the address itself rather than
    // only the menu entry.
    $this->actingAs($anjali)
        ->get('http://'.MeridianSeeder::Slug.'.'.config('tenancy.central_domain').'/admin/notification-templates')
        ->assertForbidden();
});

it('refuses somebody whose grant covers one branch only', function () {
    $person = TenantContext::run($this->meridian, function () {
        $rohit = whoRewordsMeridiansMessages('rohit');

        // The same words go to a leaver in Shimla and one in Pune, so rewording them from
        // inside one branch would be one branch rewriting what every branch sends.
        $shimla = OrgUnit::query()->where('name', 'Shimla branch')->sole();

        $role = Role::query()->create(['key' => 'branch_wording', 'name' => 'Branch wording']);
        $role->permissions()->create(['permission' => Permission::ManageNotificationTemplate]);
        $role->assignments()->create([
            'user_id' => $rohit->getKey(),
            'org_unit_id' => $shimla->getKey(),
            'includes_descendants' => true,
        ]);

        app(PermissionResolver::class)->forget();

        return $rohit;
    });

    TenantContext::run($this->meridian, function () use ($person) {
        $this->actingAs($person);

        expect(NotificationTemplateResource::canViewAny())->toBeFalse();
    });
});

it('says to clear the filter, rather than to ring us, when nothing matches it', function () {
    TenantContext::run($this->meridian, function () {
        // Vertex has reworded nothing at all, so this is the state every new client is
        // in the first time they narrow the list. What was here before told them the
        // messages had not been set up and to tell us about it.
        NotificationTemplate::query()->get()->each(function (NotificationTemplate $template): void {
            $template->reworded = false;
            $template->save();
        });

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))->test(ListNotificationTemplates::class)
            ->filterTable('reworded', true)
            ->assertSee('Clear the wording filter')
            ->assertDontSee('tell us');
    });
});

it('still calls our words ours after we improve one of them', function () {
    TenantContext::run($this->meridian, function () {
        $opening = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::CaseOpenedSubject)->sole();

        // What improving a sentence of our own looks like from this row's side: the
        // wording we ship has moved on and this company is still on the version they
        // were given. They have not written a word of it, so the list must not tell
        // them they have, and the way back must not be offered on words they never
        // chose. Written straight to the table because that is what a later change to
        // our own wording does — it is not something anybody types on the screen.
        DB::table('notification_templates')
            ->where('id', $opening->getKey())
            ->update(['subject' => 'The sentence we used to ship']);

        expect($opening->fresh()->reworded)->toBeFalse();

        Livewire::actingAs(whoRewordsMeridiansMessages('chandni'))
            ->test(EditNotificationTemplate::class, ['record' => $opening->getKey()])
            ->assertActionHidden('putOurWordingBack');
    });
});
