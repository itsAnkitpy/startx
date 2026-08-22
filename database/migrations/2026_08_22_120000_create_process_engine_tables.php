<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The engine's five tables: a process and its steps, a case, a case's steps, and the
     * history log.
     *
     * The split between them is the whole design. A process version is written once and
     * frozen, so a running case points at it instead of copying it. A case's step row is
     * written when somebody first touches the step, so a step nobody has started has no
     * row anywhere — which is why availability is worked out rather than stored, and why
     * a case cannot silently stall waiting on a row that was never created. And the
     * history is insert only at the database, because the trail is what this product is
     * sold on.
     *
     * Three columns the plan listed are deliberately absent, each because something else
     * on the row already answers them: `cases.state` and `case_steps.state` are read off
     * the timestamps and the outcome, `cases.template_version` off the template row the
     * case points at, and `case_steps.group_no` off the step definition. A second copy of
     * an answer is the magic status number this module exists to delete.
     */
    public function up(): void
    {
        $this->createProcessTemplates();
        $this->createProcessSteps();
        $this->createCases();
        $this->createCaseSteps();
        $this->createCaseEvents();
    }

    public function down(): void
    {
        Schema::dropIfExists('case_events');
        DB::statement('drop function if exists case_events_are_append_only()');

        Schema::dropIfExists('case_steps');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('process_steps');
        Schema::dropIfExists('process_templates');

        DB::statement('drop index if exists employment_records_person_and_row');
    }

    /**
     * One row per version of a process. Editing a published process writes the next
     * version as fresh rows and leaves the old ones exactly as they are, which is what
     * lets a case simply point at the version it opened on.
     */
    private function createProcessTemplates(): void
    {
        Schema::create('process_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // The stable identifier a client's own words are attached to, so renaming
            // "Exit" to "Separation" does not start a second process beside it.
            $table->string('key');
            $table->string('name');

            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft');

            // What the process is about: an exit is about an employee, an onboarding
            // about a candidate who has no account yet, a hiring request about nobody.
            // Without it the engine cannot state one rule about the subject that is true
            // of all three.
            $table->string('subject_kind');

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key', 'version']);
        });

        DB::statement(
            "alter table process_templates add constraint process_templates_status_known
               check (status in ('draft', 'published', 'archived'))"
        );

        DB::statement(
            "alter table process_templates add constraint process_templates_subject_kind_known
               check (subject_kind in ('employee', 'candidate', 'none'))"
        );

        DB::statement(
            'alter table process_templates add constraint process_templates_version_counts_from_one
               check (version >= 1)'
        );

        // The same rule the client-maintained lists carry: a padded or empty name
        // survives a screen but not a pasted file, and this one is read off a case a
        // year later.
        DB::statement(
            "alter table process_templates add constraint process_templates_key_not_blank_or_padded
               check (key = btrim(key) and key <> '')"
        );

        DB::statement(
            "alter table process_templates add constraint process_templates_name_not_blank_or_padded
               check (name = btrim(name) and name <> '')"
        );

        Rls::enable('process_templates');
    }

    /**
     * The steps of one version. An ordered list where steps sharing a group number run
     * at the same time — the whole process shape, and nothing further.
     */
    private function createProcessSteps(): void
    {
        Schema::create('process_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('template_id');

            $table->unsignedInteger('sequence');
            $table->unsignedInteger('group_no');

            // The client's own words. Renameable at any time without touching code,
            // because no rule in the engine reads it.
            $table->string('name');

            // Internal means an account holder resolved by module 03. External means
            // somebody with no account acting through a signed link.
            $table->string('participant_kind')->default('internal');

            // Who the step belongs to, in the shape module 03 resolves.
            $table->jsonb('assignee_rule');

            // A list of condition sets. The step opens when every condition in any one
            // set holds — that is OR-of-ANDs, and there is nothing beyond it.
            $table->jsonb('open_conditions')->default('[]');

            // What this step's actor may choose. A clearance allows approve and hold
            // only, so a department cannot reject a resignation it has no authority to
            // stop.
            $table->jsonb('allowed_outcomes');

            // An internal service target with no legal consequence, counted in the
            // subject's office working days. Null means the step has no target of its
            // own; the case's statutory deadline runs underneath either way.
            $table->unsignedInteger('sla_hours')->nullable();

            // Fractions of the step's own target at which the holder is nudged, and the
            // point at which their manager is told.
            $table->jsonb('reminder_rule')->nullable();

            // Notifications, letters and outbound calls, fired by module 06's pass
            // rather than by a write, because nothing is written when a step opens.
            $table->jsonb('on_open')->default('[]');
            $table->jsonb('on_complete')->default('[]');

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            // One step per position in a version. Two rows sharing a sequence is what
            // the flat-file importer merges into one step carrying two condition sets,
            // never two steps.
            $table->unique(['tenant_id', 'template_id', 'sequence']);

            $table->foreign(['tenant_id', 'template_id'])
                ->references(['tenant_id', 'id'])->on('process_templates')->cascadeOnDelete();
        });

        DB::statement(
            'alter table process_steps add constraint process_steps_positions_count_from_one
               check (sequence >= 1 and group_no >= 1)'
        );

        DB::statement(
            "alter table process_steps add constraint process_steps_name_not_blank_or_padded
               check (name = btrim(name) and name <> '')"
        );

        DB::statement(
            "alter table process_steps add constraint process_steps_participant_kind_known
               check (participant_kind in ('internal', 'external'))"
        );

        // A step must say who it belongs to and what its actor may choose. The outcomes
        // are checked against the four anybody may pick from a form: `closed_disputed`
        // and `force_closed` are produced by the two hold-resolution paths and must
        // never appear as a button on a step.
        DB::statement(
            "alter table process_steps add constraint process_steps_assignee_rule_is_an_object
               check (jsonb_typeof(assignee_rule) = 'object')"
        );

        DB::statement(
            "alter table process_steps add constraint process_steps_outcomes_are_choosable
               check (jsonb_typeof(allowed_outcomes) = 'array'
                      and jsonb_array_length(allowed_outcomes) > 0
                      and allowed_outcomes <@ '[\"approved\", \"rejected\", \"held\", \"sent_back\"]'::jsonb)"
        );

        // The three lists are checked and the maps beside them are not, on purpose. A map
        // here is legitimately empty — a process with no settings-based conditions has an
        // empty snapshot — and PHP writes an empty map as a JSON list, so a check would
        // refuse an ordinary value rather than catch a mistake. `assignee_rule` is checked
        // because it is never empty: a step has to say who it belongs to.
        DB::statement(
            "alter table process_steps add constraint process_steps_lists_are_lists
               check (jsonb_typeof(open_conditions) = 'array'
                      and jsonb_typeof(on_open) = 'array'
                      and jsonb_typeof(on_complete) = 'array')"
        );

        DB::statement(
            'alter table process_steps add constraint process_steps_target_is_positive
               check (sla_hours is null or sla_hours > 0)'
        );

        Rls::enable('process_steps');
    }

    /**
     * One run of a process. It points at the frozen version it opened on, at the person
     * it is about, and at the dated job row that was true for them at that moment.
     */
    private function createCases(): void
    {
        // What lets the two subject columns be checked together: the job row a case pins
        // has to belong to the person the case is about, or a tribunal reads Priya's
        // department off Rakesh's exit.
        DB::statement(
            'create unique index employment_records_person_and_row
               on employment_records (tenant_id, user_id, id)'
        );

        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // The version, not the process. One row per version means pointing at the
            // row is recording which version this case runs on.
            $table->unsignedBigInteger('template_id');

            // Both null for a hiring request, which is about nobody. Both null at open
            // for an onboarding, and filled in by that process's own last step. Both
            // required for an exit.
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->unsignedBigInteger('subject_employment_record_id')->nullable();

            $table->unsignedBigInteger('initiated_by')->nullable();

            $table->timestamp('opened_at');

            // The one date every legal clock on this case counts from — for an exit, the
            // leaver's last working day. It is held here rather than read from the job
            // row because that row is frozen on purpose and this date can be amended
            // while the case runs.
            $table->date('statutory_from')->nullable();

            // Two working days from that date, against the subject's office calendar as
            // it stood at open. Worked out once and never recomputed because a holiday
            // list changed underneath it.
            $table->date('statutory_due_at')->nullable();

            // Thirty calendar days from the same date, and calendar days is not an
            // oversight: the Payment of Gratuity Act counts days, so the working-day
            // calendar must not touch this one. Null where no gratuity is owed, which is
            // also what the settlement statement reads before showing the line.
            $table->date('gratuity_due_at')->nullable();

            $table->timestamp('closed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();

            // The client's own switches, and the answers about the person, frozen at
            // open. A condition is evaluated against these and never against live data,
            // so raising a threshold on Tuesday cannot close off an approval on a case
            // opened on Monday.
            $table->jsonb('settings_snapshot')->default('{}');
            $table->jsonb('subject_facts_snapshot')->default('{}');

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'template_id'])
                ->references(['tenant_id', 'id'])->on('process_templates');

            $table->foreign(['tenant_id', 'subject_user_id'])
                ->references(['tenant_id', 'id'])->on('users');

            // Three columns, so the job row has to be the subject's own. A partly null
            // key is not checked at all, which is exactly right for a case that has no
            // subject yet.
            $table->foreign(
                ['tenant_id', 'subject_user_id', 'subject_employment_record_id'],
                'cases_pinned_row_is_the_subjects_own'
            )->references(['tenant_id', 'user_id', 'id'])->on('employment_records');

            $table->foreign(['tenant_id', 'initiated_by'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'cancelled_by'])
                ->references(['tenant_id', 'id'])->on('users');
        });

        // A pinned job row with nobody to own it would slip past the key above, since a
        // key with a null in it is not checked.
        DB::statement(
            'alter table cases add constraint cases_pinned_row_has_a_subject
               check (subject_employment_record_id is null or subject_user_id is not null)'
        );

        // Closed and cancelled are two different endings and a case has one of them.
        DB::statement(
            'alter table cases add constraint cases_end_once
               check (closed_at is null or cancelled_at is null)'
        );

        // A withdrawal that records no reason and nobody who did it is the skippable
        // decision this rebuild exists to remove, arriving through a different door.
        DB::statement(
            "alter table cases add constraint cases_cancellation_is_accounted_for
               check ((cancelled_at is null) = (cancellation_reason is null)
                      and (cancelled_at is null) = (cancelled_by is null)
                      and (cancellation_reason is null
                           or (cancellation_reason = btrim(cancellation_reason) and cancellation_reason <> '')))"
        );

        // Neither deadline can exist without the date it counts from.
        DB::statement(
            'alter table cases add constraint cases_deadlines_have_a_starting_date
               check (statutory_from is not null
                      or (statutory_due_at is null and gratuity_due_at is null))'
        );

        Rls::enable('cases');
    }

    /**
     * What happened at one step of one case, and never a copy of what the step said —
     * the definition is read through the frozen version the case points at.
     *
     * A row appears when somebody first touches the step, including claiming it from a
     * shared queue. A step nobody has touched has no row, which is why there is no
     * `opened_at` here: the moment a step became available is the moment the last step
     * blocking it closed, and that is a timestamp already sitting on the closed rows.
     */
    private function createCaseSteps(): void
    {
        Schema::create('case_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('case_id');

            // Which step, read against the case's own version. A position rather than a
            // pointer, so a row cannot name a step belonging to a different process.
            $table->unsignedInteger('sequence');

            $table->unsignedBigInteger('assignee_id')->nullable();

            // The name, the address and the token for somebody with no account. An
            // external action is recorded against these and never against a user, so
            // nothing in the history reads as though an employee did it.
            $table->jsonb('external_assignee')->nullable();

            $table->timestamp('acted_at')->nullable();
            $table->string('outcome')->nullable();
            $table->jsonb('payload')->default('{}');

            // Set when a send-back replaces this attempt. The replaced row stays
            // readable; nothing anywhere is overwritten.
            //
            // A time and not a pointer at the row that replaced it, which is what the plan
            // first asked for. The pointer cannot be written: the replacing row has to
            // exist before it can be pointed at, and it cannot be inserted while this row
            // still counts as the live attempt. A time is written first and the new row
            // goes in behind it. Nothing is lost — the attempts at one step are already in
            // order, and only one of them is ever live.
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'case_id'])
                ->references(['tenant_id', 'id'])->on('cases');
            $table->foreign(['tenant_id', 'assignee_id'])
                ->references(['tenant_id', 'id'])->on('users');
        });

        // One live attempt per step, so a shared queue has exactly one winner. Restricted
        // to rows nothing has replaced, or a send-back could never re-do the step.
        DB::statement(
            'create unique index case_steps_one_live_attempt
               on case_steps (tenant_id, case_id, sequence)
              where superseded_at is null'
        );

        DB::statement(
            'alter table case_steps add constraint case_steps_position_counts_from_one
               check (sequence >= 1)'
        );

        // Exactly one holder. A row exists because somebody picked the step up, so
        // neither both nor neither is a state this table has.
        DB::statement(
            'alter table case_steps add constraint case_steps_have_one_holder
               check ((assignee_id is null) <> (external_assignee is null))'
        );

        // The two hold resolutions are here and not in a step's `allowed_outcomes`,
        // because they are produced by the two paths out of a hold rather than chosen
        // from a form.
        DB::statement(
            "alter table case_steps add constraint case_steps_outcome_known
               check (outcome is null
                      or outcome in ('approved', 'rejected', 'held', 'sent_back',
                                     'closed_disputed', 'force_closed'))"
        );

        DB::statement(
            'alter table case_steps add constraint case_steps_outcome_is_dated
               check ((acted_at is null) = (outcome is null))'
        );

        Rls::enable('case_steps');
    }

    /**
     * The trail. Insert only, and the refusal is written at the database rather than
     * left to the model, because a trail that application code can edit is not a trail.
     */
    private function createCaseEvents(): void
    {
        Schema::create('case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('case_id');

            // Null where nobody was signed in — a scheduled pass, an import, or an
            // external participant, whose address is recorded in the payload instead.
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('type');
            $table->jsonb('payload')->default('{}');

            // No `updated_at`. A row that can be updated is not what this table is.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'case_id'])
                ->references(['tenant_id', 'id'])->on('cases');
            $table->foreign(['tenant_id', 'actor_id'])
                ->references(['tenant_id', 'id'])->on('users');

            $table->index(['tenant_id', 'case_id', 'created_at']);
        });

        DB::statement(
            "alter table case_events add constraint case_events_type_not_blank_or_padded
               check (type = btrim(type) and type <> '')"
        );

        DB::statement(<<<'SQL'
            create or replace function case_events_are_append_only() returns trigger as $$
            begin
                raise exception 'case_events is add-only; a % is refused.', lower(tg_op);
            end;
            $$ language plpgsql
            SQL);

        // Truncate as well as update and delete: it is the one delete path that never
        // touches a row, so a row-level trigger would not see it.
        DB::statement(
            'create trigger case_events_refuse_update before update on case_events
               for each row execute function case_events_are_append_only()'
        );

        DB::statement(
            'create trigger case_events_refuse_delete before delete on case_events
               for each row execute function case_events_are_append_only()'
        );

        DB::statement(
            'create trigger case_events_refuse_truncate before truncate on case_events
               for each statement execute function case_events_are_append_only()'
        );

        Rls::enable('case_events');
    }
};
