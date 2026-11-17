<?php

use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily summary, module 06 step 5.
 *
 * **A person's choice is one column on their own account**, beside the time zone it is read
 * on: empty means told at once, an hour means one email a day at that hour. Settled with Ankit
 * on 17 November 2026 instead of a table of preferences whose channel would have said "email"
 * on every row.
 *
 * **A message waiting for somebody's summary is a row like any other**, with its own words, so
 * the case it is about shows it as waiting and then as sent in the summary. The summary is a
 * row of its own about no case, and each message it carried points at it.
 *
 * **Our subject line for the summary changes**, because it now carries news about requests
 * somebody raised as well as what became theirs. Rows still in our words take it; a client who
 * has written their own keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_summary_hour')->nullable()->after('locale');
        });

        DB::statement('alter table users add constraint users_daily_summary_hour_is_an_hour
            check (daily_summary_hour between 0 and 23)');

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreignId('case_id')->nullable()->change();
            $table->foreignId('digest_batch_id')->nullable()->after('triggered_by');

            $table->foreign(['tenant_id', 'digest_batch_id'])
                ->references(['tenant_id', 'id'])->on('notification_deliveries');
        });

        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_is_a_record');
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_is_a_record
               check (recipient_address = btrim(recipient_address) and recipient_address <> ''
                  and btrim(subject) <> '' and btrim(body) <> ''
                  and status in ('waiting', 'sending', 'sent', 'failed'))"
        );

        // The summary is the one message about no case, and every other message is about one.
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_only_a_summary_has_no_case
               check ((case_id is null) = (template_key = 'daily_digest'))"
        );

        // What the scheduled pass reads every minute: the few messages still waiting.
        DB::statement("create index notification_deliveries_waiting
            on notification_deliveries (tenant_id, recipient_user_id) where status = 'waiting'");

        $ours = TemplateCatalogue::definitionOf(TemplateCatalogue::DailyDigest);

        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => NotificationTemplate::query()
            ->where('key', TemplateCatalogue::DailyDigest)
            ->where('reworded', false)
            ->toBase()
            ->update(['subject' => $ours['subject'], 'updated_at' => now()])));
    }

    /**
     * Fails while any summary or any waiting message is on record, rather than deleting what
     * somebody was sent. The wording stays, as in every migration of this module.
     */
    public function down(): void
    {
        DB::statement('drop index notification_deliveries_waiting');
        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_only_a_summary_has_no_case');
        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_is_a_record');
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_is_a_record
               check (recipient_address = btrim(recipient_address) and recipient_address <> ''
                  and btrim(subject) <> '' and btrim(body) <> ''
                  and status in ('sending', 'sent', 'failed'))"
        );

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'digest_batch_id']);
            $table->dropColumn('digest_batch_id');
            $table->foreignId('case_id')->nullable(false)->change();
        });

        DB::statement('alter table users drop constraint users_daily_summary_hour_is_an_hour');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('daily_summary_hour');
        });
    }
};
