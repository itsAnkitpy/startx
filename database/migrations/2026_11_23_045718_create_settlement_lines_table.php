<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 08 step 1: every recovery and payable a clearance files becomes a line, and a line
 * is never changed or removed.
 *
 * Which money questions become lines is said by the question itself, so it is frozen with
 * its form once the form is live: what Chandni's answer on a closed exit meant cannot move
 * under it. That is also why no line is written here for exits already open. A question
 * already live cannot be marked, so an exit already open has nothing that could be owed a
 * line, and the rule on finishing never stops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->sayWhichWayAQuestionsMoneyGoes();
        $this->createSettlementLines();
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
        DB::statement('drop function if exists settlement_lines_are_append_only()');
        DB::statement('drop index if exists case_steps_case_and_row');

        Schema::table('form_fields', function (Blueprint $table): void {
            $table->dropColumn(['settles_as', 'reason_from']);
        });
    }

    /**
     * Null on both means the answer never becomes a line. Vertex's "Net amount after this
     * clearance" is a total typed by hand, and Meridian's "Annual CTC offered" is owed to
     * nobody, and both are money questions.
     */
    private function sayWhichWayAQuestionsMoneyGoes(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->string('settles_as')->nullable();

            // The key of the question on the same form that says what the money is for. Its
            // answer is kept on the line as the reason.
            $table->string('reason_from')->nullable();
        });

        // Only a money question is owed one way or the other. A number could be a count of
        // laptops.
        DB::statement(
            "alter table form_fields add constraint form_fields_only_money_is_owed
               check ((settles_as is null and reason_from is null)
                      or (type = 'money' and settles_as in ('recovery', 'payable')))"
        );
    }

    private function createSettlementLines(): void
    {
        // What lets a line name its case and its attempt together, so the two cannot
        // disagree about which exit the money is on.
        DB::statement('create unique index case_steps_case_and_row on case_steps (tenant_id, case_id, id)');

        Schema::create('settlement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('case_id');

            // The attempt that filed it. A send-back replaces the attempt and the line stays
            // on it, readable, which is how a corrected figure keeps the one it corrected.
            $table->unsignedBigInteger('case_step_id');
            $table->string('field_key');

            // Null when the answer came through a link from somebody with no account. The
            // attempt's own row names them.
            $table->unsignedBigInteger('entered_by')->nullable();

            $table->string('direction');

            // The question as it was asked, and what the form's own "what is it for"
            // question was answered.
            $table->string('label');
            $table->text('reason')->nullable();

            // Whole paise. A float on money drifts, and a drifted figure is a disputed
            // settlement.
            $table->bigInteger('amount_paise');

            // No `updated_at`. A line that can be updated is not what this table is.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['case_step_id', 'field_key']);

            $table->foreign(['tenant_id', 'case_id', 'case_step_id'], 'settlement_lines_attempt_is_on_its_case')
                ->references(['tenant_id', 'case_id', 'id'])->on('case_steps');
            $table->foreign(['tenant_id', 'entered_by'])
                ->references(['tenant_id', 'id'])->on('users');
        });

        DB::statement(
            "alter table settlement_lines add constraint settlement_lines_direction_known
               check (direction in ('recovery', 'payable'))"
        );

        // Nothing or zero writes no line, and a negative recovery is a payable.
        DB::statement(
            'alter table settlement_lines add constraint settlement_lines_amount_is_owed
               check (amount_paise > 0)'
        );

        DB::statement(<<<'SQL'
            create or replace function settlement_lines_are_append_only() returns trigger as $$
            begin
                raise exception 'settlement_lines is add-only; a % is refused.', lower(tg_op);
            end;
            $$ language plpgsql
            SQL);

        DB::statement(
            'create trigger settlement_lines_refuse_change before update or delete on settlement_lines
               for each row execute function settlement_lines_are_append_only()'
        );

        // Truncate as well, the one removal a row-level trigger never sees.
        DB::statement(
            'create trigger settlement_lines_refuse_truncate before truncate on settlement_lines
               for each statement execute function settlement_lines_are_append_only()'
        );

        Rls::enable('settlement_lines');
    }
};
