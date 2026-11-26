<?php

use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 09 step 4: the leaver answers each document through a link sent to her personal
 * address.
 *
 * The link is a row of its own, one per email sent (P13, Ankit, 26 November 2026), holding
 * what a step's link holds: the token's hash, where it went, until when, how many opens, and
 * whether a newer one replaced it. Only one is live for an exit at a time.
 *
 * An issued document takes its answer once, and nothing else about it ever changes. The
 * refusal still covers the whole row less the three answer columns, so a column added later
 * is fixed without anybody having to list it.
 *
 * Two messages to whoever issued the documents reach every existing client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('case_id');
            $table->char('token', 64)->unique();
            $table->string('sent_to');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('opens')->default(0);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'case_id'])->references(['tenant_id', 'id'])->on('cases');
        });

        DB::statement('create unique index document_links_one_live_per_case on document_links (case_id) where superseded_at is null');

        Rls::enable('document_links');

        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->string('answer')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('answered_at')->nullable();
        });

        // A letter is only received; the statement is accepted, or refused with a reason of
        // at least ten characters (P9).
        DB::statement(
            "alter table issued_documents add constraint issued_documents_answer_fits_the_kind check (
                (answer is null) = (answered_at is null)
                and (reason is null or coalesce(answer = 'refused', false))
                and (answer is null
                     or kind in ('relieving_letter', 'experience_letter') and answer = 'received'
                     or kind = 'settlement_statement' and answer = 'accepted'
                     or kind = 'settlement_statement' and answer = 'refused' and coalesce(char_length(btrim(reason)), 0) >= 10)
            )"
        );

        DB::statement(<<<'SQL'
            create or replace function issued_documents_record_is_fixed() returns trigger as $$
            begin
                if tg_op = 'UPDATE' and old.answered_at is null then
                    if to_jsonb(new) - array['answer', 'reason', 'answered_at']
                       = to_jsonb(old) - array['answer', 'reason', 'answered_at'] then
                        return new;
                    end if;
                end if;

                raise exception 'issued_documents keeps what was issued; % refused.', lower(tg_op);
            end;
            $$ language plpgsql
            SQL);

        // The two messages to whoever issued the documents, for every client who already
        // exists, added only where missing, as module 08 did.
        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => TemplateCatalogue::seed()));
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            create or replace function issued_documents_record_is_fixed() returns trigger as $$
            begin
                raise exception 'issued_documents keeps what was issued; % refused.', lower(tg_op);
            end;
            $$ language plpgsql
            SQL);

        DB::statement('alter table issued_documents drop constraint issued_documents_answer_fits_the_kind');

        // The refusal above stands again, so the columns go without touching a row.
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropColumn(['answer', 'reason', 'answered_at']);
        });

        Schema::dropIfExists('document_links');
    }
};
