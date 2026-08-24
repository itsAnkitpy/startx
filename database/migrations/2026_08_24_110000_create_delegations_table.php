<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who covers whom while somebody is away, and between which dates.
     *
     * Temporary absence only. Somebody leaving for good is a different thing with
     * different rules — it never expires and it moves reporting lines and role
     * assignments as well as a queue — and it gets its own table in step 4. The end date
     * here is deliberately required, which is the difference written into the schema
     * rather than left to be remembered: a cover with no end is a succession wearing a
     * cover's clothes.
     *
     * One row per process covered, rather than one row holding a list of them. "Priya
     * covers my exits and my hiring approvals for a fortnight" is two rows, which is what
     * a screen offering a multi-select writes anyway, and it means each can carry its own
     * dates. It also removes the shape nobody wants: there is no way to write a row that
     * covers everything, and a cover reaching every process a client runs is exactly what
     * this module rejected — "cover my exits for a fortnight" must not also hand over
     * salary changes.
     *
     * The process is named by its stable key rather than a pointer at one version of it,
     * because a client publishing a new version of their exit mid-cover has not changed
     * which process Priya agreed to cover.
     */
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // The person going away, and whoever holds their queue while they are.
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('delegate_id');

            $table->string('process_key');

            // Both ends required and both inclusive, the same convention a job row uses.
            $table->date('effective_from');
            $table->date('effective_to');

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'delegate_id'])
                ->references(['tenant_id', 'id'])->on('users');

            // Resolution asks this table the same question on every step of every case:
            // "is anybody covering one of these people, for this process, today".
            $table->index(['tenant_id', 'user_id', 'process_key'], 'delegations_whose_queue');
            $table->index(['tenant_id', 'delegate_id', 'process_key'], 'delegations_covering');
        });

        DB::statement(
            'alter table delegations add constraint delegations_end_after_start
               check (effective_to >= effective_from)'
        );

        // Nobody covers themselves. It would read as a cover in the record and mean
        // nothing at all, and it is the one way a row here can be pure noise.
        DB::statement(
            'alter table delegations add constraint delegations_are_not_self_cover
               check (user_id <> delegate_id)'
        );

        Rls::enable('delegations');
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
