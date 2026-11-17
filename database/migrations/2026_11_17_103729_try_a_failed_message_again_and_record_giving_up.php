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
 * When a message does not arrive, module 06 step 6.
 *
 * **A message that failed is tried again, and "given up" is a state of its own**, beside a
 * count of how many times it has been tried again. Settled with Ankit on 17 November 2026: a
 * "given up" worked out from the count would bring every given-up message back to life the day
 * somebody raised the number of tries, and tell whoever raised the case a second time.
 *
 * **A message given up on may have no address**, which is the link for a leaver with no
 * personal address on record: it never left, and the case shows it as could not be sent.
 *
 * **Our wording for "a message did not reach somebody" changes**, because it now also tells
 * somebody a link could not be sent for want of an address, and that a daily summary carrying
 * several of their cases did not arrive. Rows still in our words take it; a client who has
 * written their own keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedSmallInteger('retries')->default(0)->after('status');
        });

        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_is_a_record');
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_is_a_record
               check (recipient_address = btrim(recipient_address)
                  and (recipient_address <> '' or status = 'undelivered')
                  and btrim(subject) <> '' and btrim(body) <> ''
                  and status in ('waiting', 'sending', 'sent', 'failed', 'undelivered'))"
        );

        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_failure_is_explained');
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_failure_is_explained
               check ((status in ('failed', 'undelivered')) = (failure_reason is not null))"
        );

        // What the scheduled pass reads every minute: the few messages still being tried.
        DB::statement("create index notification_deliveries_failed
            on notification_deliveries (tenant_id) where status = 'failed'");

        $ours = TemplateCatalogue::definitionOf(TemplateCatalogue::MessageUndelivered);

        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => NotificationTemplate::query()
            ->where('key', TemplateCatalogue::MessageUndelivered)
            ->where('reworded', false)
            ->toBase()
            ->update(['body' => $ours['body'], 'updated_at' => now()])));
    }

    /**
     * Fails while any message given up on is on record, rather than deleting what somebody was
     * told. The wording stays, as in every migration of this module.
     */
    public function down(): void
    {
        DB::statement('drop index notification_deliveries_failed');

        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_failure_is_explained');
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_failure_is_explained
               check ((status = 'failed') = (failure_reason is not null))"
        );

        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_is_a_record');
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_is_a_record
               check (recipient_address = btrim(recipient_address) and recipient_address <> ''
                  and btrim(subject) <> '' and btrim(body) <> ''
                  and status in ('waiting', 'sending', 'sent', 'failed'))"
        );

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('retries');
        });
    }
};
