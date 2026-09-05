<?php

use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A case's number, counted from one inside each client company.
     *
     * The screens have shown the row's own key until now, which is counted across every
     * client on the platform — so a client's first four cases could read #7, #11, #12 and
     * #26, and the gaps say roughly how much work every other client is doing. A number a
     * client is asked to quote on the phone must not be a fact about anybody else.
     *
     * **Counting from one per client rather than a prefixed sequence.** A billing product
     * gives each customer's invoices a random prefix because an invoice leaves the
     * building and is read by somebody with no account; a case number is only ever read
     * inside one client's own panel by somebody signed into it, so the prefix buys nothing
     * here. Gaps are not guarded against either — a case cannot be deleted, so the only
     * way to make one is to fail while opening a case, and a client who never sees #4 has
     * lost nothing.
     *
     * Existing rows are numbered in the order they opened, per client, which is the same
     * order the counter carries on in. Through the audited cross-client path, because the
     * table obeys row-level security and a migration has no client in scope.
     */
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('tenant_id');
        });

        TenantContext::cross(function (): void {
            DB::statement(
                'update cases
                    set number = counted.number
                   from (select id, row_number() over (partition by tenant_id order by id) as number
                           from cases) as counted
                  where cases.id = counted.id'
            );
        }, 'numbering every existing case within its own client company');

        DB::statement('alter table cases alter column number set not null');
        DB::statement('alter table cases add constraint cases_number_counts_from_one check (number >= 1)');

        Schema::table('cases', function (Blueprint $table) {
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'number']);
            $table->dropColumn('number');
        });
    }
};
