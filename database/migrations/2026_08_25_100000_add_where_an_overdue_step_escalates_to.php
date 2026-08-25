<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who else may act on a step once its own target has run out.
     *
     * Written in the same shape as `assignee_rule` and read by the same six ways of
     * finding people, because a seventh way of naming somebody would be a second
     * vocabulary a client's administrator has to learn and a second place the answer can
     * be wrong.
     *
     * **They are added to the people who can act; nobody is removed.** So this column can
     * only ever widen a step's set, never narrow it, and an overdue step cannot be
     * escaped by escalating it — which is the entire reason the rule is written this way
     * rather than as a reassignment.
     *
     * Null means the step widens to nobody when it runs late. It still shows as past its
     * deadline to whoever already holds it, and module 06's one scheduled pass still
     * chases them; what it does not do is grow its list of people.
     */
    public function up(): void
    {
        Schema::table('process_steps', function (Blueprint $table) {
            $table->jsonb('escalate_to')->nullable()->after('reminder_rule');
        });

        DB::statement(
            "alter table process_steps add constraint process_steps_escalation_is_an_object
               check (escalate_to is null or jsonb_typeof(escalate_to) = 'object')"
        );
    }

    public function down(): void
    {
        Schema::table('process_steps', function (Blueprint $table) {
            $table->dropColumn('escalate_to');
        });
    }
};
