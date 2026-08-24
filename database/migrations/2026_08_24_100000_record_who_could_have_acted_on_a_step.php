<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who was being asked, written down at the moment the step was picked up.
     *
     * Module 03 works out whose job a step is every time somebody asks, and stores nothing
     * — which is what keeps the answer right as people change role, go on leave and leave
     * the company. The cost of that is a question nobody can answer afterwards: Deepak
     * cleared Rakesh's exit in August, and by February the IT team is different people, so
     * the record can say who signed but not who *could* have. In front of a tribunal that
     * is the difference between "the right person signed" and "somebody signed".
     *
     * A list on the row rather than a table of its own, and that was a decision taken twice
     * — an earlier draft of module 03 had a `case_step_candidates` table and it was
     * rejected, because a step nobody has picked up has no row for a candidate to hang off.
     * Written once, when the row is created, and never touched again: what it records is the
     * queue that saw the step, and a decision on this row is already proof the person who
     * took it was still in that queue at the time, because nothing can be recorded here
     * without passing that check first.
     *
     * Null on a step answered by somebody with no account. There is no set to record —
     * permission there is the signed link sent to their address and nothing else.
     */
    public function up(): void
    {
        Schema::table('case_steps', function (Blueprint $table) {
            $table->jsonb('candidates_at_claim')->nullable()->after('external_assignee');
        });

        // Riding along: a cancellation reason is prose, and the length it is allowed to be
        // is the engine's rule, refused in words a client can read. Saying 255 a second
        // time in the column type only decides which of the two complains first.
        DB::statement('alter table cases alter column cancellation_reason type text');
    }

    public function down(): void
    {
        Schema::table('case_steps', function (Blueprint $table) {
            $table->dropColumn('candidates_at_claim');
        });

        DB::statement('alter table cases alter column cancellation_reason type varchar(255)');
    }
};
