<?php

use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chase button, module 06 step 3.
 *
 * **Who pressed it.** Every chase somebody presses by hand is recorded with their name, which
 * is what keeps it a person chasing a person rather than an anonymous system mail. Null on
 * everything the product sends by itself.
 *
 * **Our wording of the chase loses its due date.** A hiring request is about nobody, so it
 * has no calendar and its steps no due date, and "It is due by ." is what Anjali's chase
 * would have read. The rows still in our words take the new wording; a client who has
 * written their own keeps it, which is the rule this module settled on 6 September 2026.
 * Written through the query rather than the model, because saving the model marks the
 * words as the client's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreignId('triggered_by')->nullable()->after('recipient_address');

            $table->foreign(['tenant_id', 'triggered_by'])
                ->references(['tenant_id', 'id'])->on('users');
        });

        $ours = TemplateCatalogue::definitionOf(TemplateCatalogue::StepNudgeHolder);

        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => NotificationTemplate::query()
            ->where('key', TemplateCatalogue::StepNudgeHolder)
            ->where('reworded', false)
            ->toBase()
            ->update(['subject' => $ours['subject'], 'body' => $ours['body'], 'updated_at' => now()])));
    }

    /** The wording stays: words a client is reading are not taken back by a rollback. */
    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'triggered_by']);
            $table->dropColumn('triggered_by');
        });
    }
};
