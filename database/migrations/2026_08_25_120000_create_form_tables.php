<?php

use App\Models\FormDefinition;
use App\Models\FormField;
use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a step asks for, defined by the client rather than by us.
 *
 * The whole point of these two tables is that IT's clearance asking whether the mailbox
 * is off, and finance's asking for the imprest card back, are the same code and different
 * rows. Holistart answered the same need with 22 add-column migrations against one table
 * and about 193 column names, which is the thing this module exists to not do again.
 *
 * **A published form is frozen, exactly as a published process version is.** The reason is
 * the same and it is the one requirement this module cannot give up: the finance clearance
 * on Anjali's closed exit has to still ask the questions it asked when she answered it.
 * Typeform loses the old answers when a question's type changes and keeps no history at
 * all; ServiceNow removes a value from requests submitted years earlier when somebody
 * hides or deletes the question, and their own advice is never to edit one. Both checked
 * 25 August 2026. Freezing means an edit cannot reach backwards rather than should not,
 * and it needs no copy of the questions stored on anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createFormDefinitions();
        $this->createFormFields();
        $this->pointAStepAtItsForm();
        $this->freezePublishedForms();
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists form_fields_refuse_change_once_live on form_fields');
        DB::statement('drop function if exists form_fields_are_frozen_once_live()');

        Schema::table('process_steps', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'form_definition_id']);
            $table->dropColumn('form_definition_id');
        });

        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_definitions');
    }

    /**
     * One row per version, in the same shape `process_templates` uses, so that a step
     * pointing at a form is pointing at one particular set of questions and not at a name
     * whose meaning can move underneath it.
     */
    private function createFormDefinitions(): void
    {
        Schema::create('form_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // The stable identifier, so renaming "Finance clearance" to "Accounts
            // clearance" does not start a second form beside it.
            $table->string('key');
            $table->string('name');

            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default(FormDefinition::Draft);

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key', 'version']);
        });

        DB::statement(sprintf(
            "alter table form_definitions add constraint form_definitions_status_known
               check (status in ('%s'))",
            implode("', '", FormDefinition::Statuses),
        ));

        DB::statement(
            'alter table form_definitions add constraint form_definitions_version_counts_from_one
               check (version >= 1)'
        );

        // The same rule every client-maintained name carries: padding survives a screen
        // but not a pasted file, and these are read off a closed case a year later.
        DB::statement(
            "alter table form_definitions add constraint form_definitions_key_not_blank_or_padded
               check (key = btrim(key) and key <> '')"
        );

        DB::statement(
            "alter table form_definitions add constraint form_definitions_name_not_blank_or_padded
               check (name = btrim(name) and name <> '')"
        );

        // Two live versions of one form would leave the questions a step asks decided by
        // whichever row the database happened to return first.
        DB::statement(sprintf(
            "create unique index form_definitions_one_published_version
               on form_definitions (tenant_id, key)
              where status = '%s'",
            FormDefinition::Published,
        ));

        Rls::enable('form_definitions');
    }

    /**
     * The questions themselves. `type` is closed at twelve and adding a thirteenth is a
     * code change with a reason behind it — Airtable, the most permissive product in this
     * space, fixes its own list at 34 and lets nobody add to it (checked 25 August 2026).
     */
    private function createFormFields(): void
    {
        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('form_definition_id');

            // What the answer is stored under, and what a step's opening condition names
            // when it waits on this answer.
            $table->string('key');

            // The client's own words on the screen. Renameable without touching an answer,
            // because nothing reads it.
            $table->string('label');

            $table->string('type');
            $table->boolean('required')->default(false);

            // The choices, for the two types that have any. A list of
            // `{value, label}` — never a bare list, because a client renaming a choice
            // must not change what an answer already given meant.
            $table->jsonb('options')->default('[]');

            // Named limits only — `{"min": 0, "max_length": 50}` — never a rule string.
            // A client-editable row that reached Laravel's rule parser as text would let
            // whoever edits the form choose which rules run, and that is a trust boundary,
            // not a convenience.
            $table->jsonb('validation')->default('{}');

            $table->unsignedInteger('sort_order');

            // When this question is asked at all, in the same flat one-comparison shape
            // module 02's opening conditions use. Null means always.
            $table->jsonb('visible_if')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            // One question per key in a form, and one per position, so an answer can never
            // be ambiguous about which question it answers.
            $table->unique(['tenant_id', 'form_definition_id', 'key']);
            $table->unique(['tenant_id', 'form_definition_id', 'sort_order']);

            $table->foreign(['tenant_id', 'form_definition_id'])
                ->references(['tenant_id', 'id'])->on('form_definitions')->cascadeOnDelete();
        });

        DB::statement(sprintf(
            "alter table form_fields add constraint form_fields_type_known
               check (type in ('%s'))",
            implode("', '", FormField::Types),
        ));

        DB::statement(
            "alter table form_fields add constraint form_fields_key_not_blank_or_padded
               check (key = btrim(key) and key <> '')"
        );

        DB::statement(
            "alter table form_fields add constraint form_fields_label_not_blank_or_padded
               check (label = btrim(label) and label <> '')"
        );

        // A key that is not a plain identifier cannot be named by a condition, cannot be
        // a form input's name, and cannot be looked up in a stored answer without
        // quoting rules nobody will remember.
        DB::statement(
            "alter table form_fields add constraint form_fields_key_is_an_identifier
               check (key ~ '^[a-z][a-z0-9_]*$')"
        );

        DB::statement(
            "alter table form_fields add constraint form_fields_options_are_a_list
               check (jsonb_typeof(options) = 'array')"
        );

        DB::statement(
            'alter table form_fields add constraint form_fields_positions_count_from_one
               check (sort_order >= 1)'
        );

        Rls::enable('form_fields');
    }

    /**
     * The column module 02 deliberately did not create, so that the pointer and the table
     * it points at would arrive together. Null is the default and means the step asks
     * nothing — a manager sign-off that is only a decision needs no form.
     */
    private function pointAStepAtItsForm(): void
    {
        Schema::table('process_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('form_definition_id')->nullable()->after('name');

            $table->foreign(['tenant_id', 'form_definition_id'])
                ->references(['tenant_id', 'id'])->on('form_definitions');
        });
    }

    /**
     * The freeze, written at the database for the reason module 02 wrote its own there:
     * the whole protection a closed step has is that the questions it asked cannot
     * change, and a protection only the application applies is one an import, a console
     * command or a future editing screen walks straight round.
     *
     * Archived is frozen too, because the versions closed cases point at are archived
     * ones and they stay readable for good.
     */
    private function freezePublishedForms(): void
    {
        DB::statement(<<<'SQL'
            create or replace function form_fields_are_frozen_once_live() returns trigger as $$
            declare
                current_status text;
            begin
                select status into current_status
                  from form_definitions
                 where id = coalesce(new.form_definition_id, old.form_definition_id);

                if current_status in ('published', 'archived') then
                    raise exception
                        'form [%] is % and its questions cannot be changed; publish a new version instead.',
                        coalesce(new.form_definition_id, old.form_definition_id), current_status;
                end if;

                return coalesce(new, old);
            end;
            $$ language plpgsql
            SQL);

        // Delete as well as insert and update: removing a question from a live form
        // changes what it asks exactly as much as renaming one does. Deleting the form
        // itself still works — the cascade removes the parent first, so this reads no
        // status and lets the rows through.
        DB::statement(
            'create trigger form_fields_refuse_change_once_live
               before insert or update or delete on form_fields
               for each row execute function form_fields_are_frozen_once_live()'
        );
    }
};
