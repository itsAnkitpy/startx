<?php

use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raising an exit, module 07 step 1.
 *
 * **How somebody's employment ended, stored on the case beside the date it ended.** The
 * gratuity rule reads it: the Code on Social Security waives the five years for disablement
 * and for a fixed-term contract that simply ends. It is on the case rather than on the job
 * row for the reason the last working day is — the job row is frozen so the case's record
 * cannot drift, and this answer has to be correctable. A fixed list, because the law reads
 * these words and no others; death is left out on purpose until a flow exists that writes to
 * nobody about it. Null on a case that is not about somebody leaving.
 *
 * **One open exit about a person at a time.** Two would give one person two legal deadlines,
 * so it is the case carrying a legal clock that is counted: something else about them with no
 * deadline, an asset return say, still runs beside it. A case closed or withdrawn does not
 * count, so a withdrawn resignation can be raised again and a rehire can leave a second time.
 *
 * **A new message for every client who already exists: the person a case is about told it
 * was turned down.** Somebody may now raise their own exit, and one turned down at the
 * manager's approval told them nothing. Added only where missing, so nothing a client wrote
 * is touched; the wording stays on the way down, as in every migration of module 06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('employment_ended_by')->nullable()->after('statutory_from');
        });

        DB::statement(
            "alter table cases add constraint cases_employment_ended_by_is_known
               check (employment_ended_by is null
                   or employment_ended_by in ('resignation', 'fixed_term_ended', 'retirement', 'dismissal', 'disablement'))"
        );

        DB::statement('create unique index cases_one_open_exit_per_person
            on cases (tenant_id, subject_user_id)
            where statutory_from is not null and closed_at is null and cancelled_at is null');

        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => TemplateCatalogue::seed()));
    }

    public function down(): void
    {
        DB::statement('drop index cases_one_open_exit_per_person');

        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('employment_ended_by');
        });
    }
};
