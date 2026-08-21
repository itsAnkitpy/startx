<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The working-day calendar: which weekdays an office does not work, and which dates
     * it is closed.
     *
     * It exists because the deadline the whole product is sold on is counted in working
     * days, not hours. Two working days after a Friday resignation is the Tuesday; forty-
     * eight hours after it is the Sunday. The calendar hangs off the office rather than
     * off the client company because Indian public holidays are set by state and follow
     * where a person actually works, so a client with an office in Shimla and one in
     * Bengaluru genuinely has two of them.
     *
     * Nothing is seeded. A holiday list is state-set, changes every year, and a stale one
     * carrying our name on a wrong legal date is worse than an empty one a client can see
     * is empty.
     */
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            // Day numbers as Carbon counts them: Sunday is 0, Saturday is 6. Saturday and
            // Sunday by default, and per office rather than per client because a Gulf
            // office runs a Friday-Saturday weekend.
            $table->jsonb('weekly_off_days')->default('[0, 6]')->after('address_block');
        });

        // Two rules, both expressible without a subquery, which a check constraint cannot
        // hold. The first refuses anything that is not a set of weekday numbers -- a
        // stray 7, a string, a bare number instead of a list. The second refuses an
        // office that works no day of the week at all, which would otherwise make
        // counting forward to the next working day run forever.
        DB::statement(
            "alter table offices add constraint offices_weekly_off_days_are_weekdays
               check (jsonb_typeof(weekly_off_days) = 'array'
                      and weekly_off_days <@ '[0, 1, 2, 3, 4, 5, 6]'::jsonb
                      and not ('[0, 1, 2, 3, 4, 5, 6]'::jsonb <@ weekly_off_days))"
        );

        Schema::create('office_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('office_id');

            $table->date('date');

            // Required rather than decorative: it is what a screen shows a client when it
            // explains why a deadline moved a day.
            $table->string('name');

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            // One row per date per office. A client pasting last year's list over this
            // year's is the ordinary way this gets tried.
            $table->unique(['tenant_id', 'office_id', 'date']);

            $table->foreign(['tenant_id', 'office_id'])
                ->references(['tenant_id', 'id'])->on('offices');
        });

        // The same rule the two client-maintained lists already carry, and for the same
        // reason: a padded or empty name survives a screen but not a pasted file, and it
        // reaches whoever is being told why their deadline moved.
        DB::statement(
            "alter table office_holidays add constraint office_holidays_name_not_blank_or_padded
               check (name = btrim(name) and name <> '')"
        );

        Rls::enable('office_holidays');
    }

    public function down(): void
    {
        Schema::dropIfExists('office_holidays');

        DB::statement('alter table offices drop constraint offices_weekly_off_days_are_weekdays');

        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('weekly_off_days');
        });
    }
};
