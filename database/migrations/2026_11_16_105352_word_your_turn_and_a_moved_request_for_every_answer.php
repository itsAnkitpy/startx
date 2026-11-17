<?php

use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * Module 06 step 4 sends two messages nothing sent before, and both had wording that read
 * wrongly once real cases went through them.
 *
 * **"It is your turn"** said "It is due by ." on a hiring request, which is about nobody and
 * has no due date, and said nothing to somebody covering for a colleague away. It now carries
 * the date as a whole sentence or not at all, and whose work it is.
 *
 * **"Your request has moved on"** said a step was finished when it had been put on hold or
 * sent back. It now says which of the three happened.
 *
 * The rows still in our words take the new wording; a client who has written their own keeps
 * it, which is the rule this module settled on 6 September 2026. Written through the query
 * rather than the model, because saving the model marks the words as the client's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = [TemplateCatalogue::StepAvailableHolder, TemplateCatalogue::CaseMovedInitiator];

        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, function () use ($keys): void {
            foreach ($keys as $key) {
                $ours = TemplateCatalogue::definitionOf($key);

                NotificationTemplate::query()
                    ->where('key', $key)
                    ->where('reworded', false)
                    ->toBase()
                    ->update(['subject' => $ours['subject'], 'body' => $ours['body'], 'updated_at' => now()]);
            }
        }));
    }

    /** The wording stays: words a client is reading are not taken back by a rollback. */
    public function down(): void {}
};
