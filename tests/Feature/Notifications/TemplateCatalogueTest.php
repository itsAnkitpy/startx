<?php

use App\Exceptions\NotificationTemplateRefused;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
| The messages this product sends, and the wording each client company holds for them.
|
| What is being protected here is one failure: a step reaching the moment it should send
| something and having nothing to send. That is the invisible kind — no error, no broken
| page, just a person who is never told — so every check below is about there always being
| words behind every message, for every client, whenever they signed up.
|
| The wording itself is not asserted line by line. What is asserted is that it exists, that
| a client's own version survives everything, and that nothing can leave a message wordless.
*/

/** A client company with the catalogue in front of it, and its rows by message. */
function aClientHoldingTheCatalogue(string $slug = 'acme'): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'name' => ucfirst($slug).' Ltd']);

    $templates = TenantContext::run($tenant, fn () => TemplateCatalogue::seed());

    return [$tenant, $templates];
}

it('gives a client words for every message this product sends', function () {
    [$tenant, $templates] = aClientHoldingTheCatalogue();

    expect($templates)->toHaveCount(count(TemplateCatalogue::definitions()));

    TenantContext::run($tenant, function () {
        foreach (TemplateCatalogue::definitions() as $key => $ours) {
            $held = NotificationTemplate::query()->where('key', $key)->sole();

            // Every one of them has a subject line and a body, is named in words rather
            // than by its key, and says who reads it and what sends it. A message with
            // any of those missing is one somebody would have to ask us about.
            expect($held->subject)->toBe($ours['subject'])
                ->and(trim($held->body))->not->toBe('')
                ->and($held->name())->not->toBe($key)
                ->and($held->goesTo())->not->toBe('')
                ->and($held->sentWhen())->not->toBe('');
        }
    });
});

it('gives a message we add later to a client who already exists, without touching what they wrote', function () {
    [$tenant] = aClientHoldingTheCatalogue();

    TenantContext::run($tenant, function () {
        $reminder = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepReminderHolder)->sole();

        $reminder->update(['subject' => 'Our own words', 'body' => 'Clear this today.']);

        // A message taken away stands in for one we have not written yet: the client is
        // a message short, exactly as Meridian would be the day we ship a thirteenth.
        // Removed straight from the table on purpose, because the record itself refuses
        // a delete and the state being set up here is one no client can reach — it is
        // the state a client is in *before* a message of ours exists.
        DB::table('notification_templates')
            ->where('key', TemplateCatalogue::CaseBreachWarning)
            ->delete();

        expect(NotificationTemplate::query()->count())->toBe(count(TemplateCatalogue::definitions()) - 1);

        TemplateCatalogue::seed();

        expect(NotificationTemplate::query()->count())->toBe(count(TemplateCatalogue::definitions()));

        // The new one arrived in our words, and the one they had rewritten is still in
        // theirs. Both halves matter: the first is the rule that a later message reaches
        // an existing client, the second is that our improvement never overwrites them.
        $arrived = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::CaseBreachWarning)->sole();

        expect($arrived->subject)
            ->toBe(TemplateCatalogue::definitionOf(TemplateCatalogue::CaseBreachWarning)['subject'])
            ->and($arrived->reworded)->toBeFalse();

        expect($reminder->fresh()->subject)->toBe('Our own words')
            ->and($reminder->fresh()->reworded)->toBeTrue();
    });
});

it('keeps one client company\'s wording out of another\'s', function () {
    [$meridian] = aClientHoldingTheCatalogue('meridian');
    [$vertex] = aClientHoldingTheCatalogue('vertex');

    TenantContext::run($meridian, function () {
        NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepReminderHolder)
            ->sole()
            ->update(['subject' => 'Meridian is on the hook for a legal deadline']);
    });

    TenantContext::run($vertex, function () {
        // Vertex sees its own row and only its own, so the words one client writes never
        // go out in another's name. The database refuses the read; the count says so.
        expect(NotificationTemplate::query()->count())->toBe(count(TemplateCatalogue::definitions()));

        expect(NotificationTemplate::query()->where('key', TemplateCatalogue::StepReminderHolder)->sole()->subject)
            ->toBe(TemplateCatalogue::definitionOf(TemplateCatalogue::StepReminderHolder)['subject']);
    });
});

it('refuses to leave a message with no words behind it', function () {
    [$tenant] = aClientHoldingTheCatalogue();

    TenantContext::run($tenant, function () {
        $reminder = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepReminderHolder)->sole();

        // Deleting the words is the failure this whole module exists to remove: a step
        // that reaches its moment and sends nothing. There is no switching one off either.
        expect(fn () => $reminder->delete())
            ->toThrow(NotificationTemplateRefused::class, 'cannot be deleted');

        expect(NotificationTemplate::query()->where('key', TemplateCatalogue::StepReminderHolder)->exists())
            ->toBeTrue();
    });
});

it('refuses to move one message\'s wording onto another message', function () {
    [$tenant] = aClientHoldingTheCatalogue();

    TenantContext::run($tenant, function () {
        $reminder = NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepReminderHolder)->sole();

        // Set directly rather than filled, because a key is deliberately not one of the
        // fields a form may write — so this is the only shape the mistake can arrive in.
        // What it catches: a client's chase-up wording going out to a leaver as the notice
        // that money has been claimed against them.
        $reminder->key = TemplateCatalogue::MoneyClaimedSubject;

        expect(fn () => $reminder->save())
            ->toThrow(NotificationTemplateRefused::class, 'cannot be moved onto another message');
    });
});

it('refuses wording for a message this product does not send', function () {
    [$tenant] = aClientHoldingTheCatalogue();

    TenantContext::run($tenant, function () {
        $invented = new NotificationTemplate;
        $invented->key = 'a_message_nobody_wrote';
        $invented->fill(['subject' => 'Anything', 'body' => 'Anything at all.']);

        expect(fn () => $invented->save())
            ->toThrow(NotificationTemplateRefused::class, 'does not send a message called');

        // And a row with no message named at all, which is what a seeder gets when it
        // tries to fill a key through a form's own field list. Our refusal rather than a
        // type error out of the catalogue.
        $nameless = new NotificationTemplate;
        $nameless->fill(['subject' => 'Anything', 'body' => 'Anything at all.']);

        expect(fn () => $nameless->save())
            ->toThrow(NotificationTemplateRefused::class, 'does not send a message called');
    });
});

it('names every token its own wording uses, so nothing goes out with a gap in it', function () {
    // Each message may only put in the values its own list names, and the wording we ship
    // is the first thing that has to obey that — a token in our own default that is not on
    // the list would render as nothing and send a sentence with a hole in it.
    foreach (TemplateCatalogue::definitions() as $key => $definition) {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $definition['subject'].$definition['body'], $found);

        $used = array_unique($found[1]);
        $allowed = $definition['tokens'];

        expect(array_diff($used, $allowed))->toBe([], "[{$key}] uses a token it does not allow");
    }
});
