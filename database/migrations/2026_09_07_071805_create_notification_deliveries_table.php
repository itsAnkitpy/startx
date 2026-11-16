<?php

use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every message this product has sent about a case, in the words it actually went out
     * in.
     *
     * **It names a step by the case and the step's number, never by a step row**, and that
     * is the whole reason this module exists rather than a shortcut. The step that most
     * needs chasing is the one nobody has opened, and module 02 writes no row for such a
     * step — a foreign key to `case_steps` would leave this table unable to record exactly
     * the chase-ups that matter.
     *
     * **The subject and the body are stored as sent.** A client may rewrite their chase-up
     * whenever they like, so looking the wording up afterwards would let them quietly
     * rewrite what they told somebody. Every other fact on a case is frozen; this was the
     * one that was not.
     *
     * **Three columns the plan named are not here, and one is deferred to each of three
     * later steps.** `reason` is gone because each of the six reasons the plan listed
     * names exactly one message in the catalogue, so the column would be the message's own
     * key spelled a second way, free to disagree with it; what a screen shows as "why" is
     * the catalogue's own sentence, which reads better than the word `transition` would.
     * `queued_at` is gone because with the send going out straight after the case's own
     * write there is no queue to wait in, so it would hold the same instant as
     * `created_at` and imply a delay that does not exist. Deferred: `triggered_by` arrives
     * with the chase button that records who pressed it, `digest_batch_id` with the daily
     * bundle, and `provider_message_id` with the step that matches a bounce from a mail
     * provider back to the message it was about.
     *
     * @see TemplateCatalogue for what each message is called and what sends it
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('case_id');

            // Which step of the case this message was about, by the step's own number in
            // the process. Null for a message about the case as a whole.
            $table->unsignedSmallInteger('step_sequence')->nullable();

            // Who it went to. The account is null for somebody who has none — a candidate,
            // or a leaver answering through a link — and the address is filled either way,
            // so the record survives the account being deleted and reads as the record a
            // tribunal would read.
            $table->foreignId('recipient_user_id')->nullable();
            $table->string('recipient_address');

            $table->string('template_key');
            $table->string('channel');

            $table->string('subject');
            $table->text('body');

            // `sent_at` is empty until the mail server takes the message, which is the one
            // thing here that is genuinely unknown at the moment the row is written.
            $table->timestamp('sent_at')->nullable();
            $table->string('status');
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'case_id'])
                ->references(['tenant_id', 'id'])->on('cases');
            $table->foreign(['tenant_id', 'recipient_user_id'])
                ->references(['tenant_id', 'id'])->on('users');

            // What the case's own page reads: every message about one case, newest first.
            $table->index(['tenant_id', 'case_id', 'created_at']);
        });

        // A message with no address, no words or no state is not a record of anything, and
        // a padded address is one nothing will ever be matched against.
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_is_a_record
               check (recipient_address = btrim(recipient_address) and recipient_address <> ''
                  and btrim(subject) <> '' and btrim(body) <> ''
                  and status in ('sending', 'sent', 'failed'))"
        );

        // A message that failed says why, and one that has not failed does not. Without
        // this a screen can show a reason beside a message that arrived perfectly well,
        // which is the sort of thing nobody notices until a client asks about it.
        DB::statement(
            "alter table notification_deliveries add constraint notification_deliveries_failure_is_explained
               check ((status = 'failed') = (failure_reason is not null))"
        );

        Rls::enable('notification_deliveries');

        // The thirteenth message, and the first real use of the rule step 1 built: a
        // message added after a client company exists reaches that client, and a client
        // who has already reworded something keeps their own words.
        //
        // This file sorts before the one that makes the messages table. Where that one has
        // not run yet, it seeds every message itself, this one included.
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        Tenant::query()->get()->each(function (Tenant $tenant): void {
            TenantContext::run($tenant, function (): void {
                TemplateCatalogue::seed();
            });
        });
    }

    /**
     * The thirteenth message's rows are left where they are. Taking them out would delete
     * one client company's own wording for it, and this table going away is not a reason
     * to lose words somebody typed.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
