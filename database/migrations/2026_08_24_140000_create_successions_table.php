<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who took over from somebody who left for good, and inside which exit it was
     * settled.
     *
     * Permanent, and that is the whole difference from a cover. A cover is somebody
     * being away for a fortnight: it has a required end date, it moves a queue and
     * nothing else, and the person comes back to their own work. A succession never
     * ends, and it moves three things at once — the approvals the leaver had opened,
     * the roles they held, and the people who reported to them. Workday keeps the two
     * apart in the same words and for the same reason, so they are two tables here
     * rather than one table with an open-ended date.
     *
     * `effective_at` is the day the handover takes effect, which is normally the
     * leaver's last working day. It is the date the moved reporting rows begin on, and
     * the day before it is where their previous rows end — so the org chart as it stood
     * the week before still reads correctly.
     *
     * The case is the exit that caused it, and it is not optional: a handover that
     * happened outside a case is a change nobody can trace back to a reason, which is
     * the failure this product is sold against. Recording *who confirmed it* is
     * separate from recording who was nominated, because the manager names the
     * successor and HR is the one who confirms.
     */
    public function up(): void
    {
        Schema::create('successions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // The person leaving, and whoever takes the work on.
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');

            // The exit this was settled inside.
            $table->unsignedBigInteger('case_id');

            $table->date('effective_at');

            // Whoever confirmed it. Null where a row came from a seed or an import
            // rather than from a person, the same convention as a job row.
            $table->unsignedBigInteger('recorded_by')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'from_user_id'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'to_user_id'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'case_id'])
                ->references(['tenant_id', 'id'])->on('cases');
            $table->foreign(['tenant_id', 'recorded_by'])
                ->references(['tenant_id', 'id'])->on('users');

            // "What happened to Rakesh's work when he left" is the question this table
            // is read with.
            $table->index(['tenant_id', 'from_user_id']);
        });

        // Nobody succeeds themselves. It would move nothing and read in the history as
        // a handover that took place.
        DB::statement(
            'alter table successions add constraint successions_are_not_self_succession
               check (from_user_id <> to_user_id)'
        );

        Rls::enable('successions');

        // The link from a moved reporting line back to the exit that moved it.
        //
        // A succession inserts a fresh job row for each of the leaver's direct reports
        // rather than repointing a column, so the old line stays readable. Without this
        // column the new rows would say only that somebody's manager changed on a date;
        // with it, the org chart movement and the exit that caused it can be read from
        // either end — which is what somebody reviewing the exit a year later needs.
        Schema::table('employment_records', function (Blueprint $table) {
            $table->unsignedBigInteger('caused_by_case_id')->nullable()->after('change_reason');

            $table->foreign(['tenant_id', 'caused_by_case_id'])
                ->references(['tenant_id', 'id'])->on('cases');

            $table->index(['tenant_id', 'caused_by_case_id']);
        });
    }

    public function down(): void
    {
        Schema::table('employment_records', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'caused_by_case_id']);
            $table->dropIndex(['tenant_id', 'caused_by_case_id']);
            $table->dropColumn('caused_by_case_id');
        });

        Schema::dropIfExists('successions');
    }
};
