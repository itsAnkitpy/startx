<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The job, the equipment, the statutory identifiers and the Aadhaar facts. Four
     * tables, and the split between them matters more than the field list: identity
     * is a current value, the job is dated history, an identifier is separately
     * permissioned and encrypted, and the Aadhaar facts sit alone so there is one
     * place to point at and one row to delete.
     *
     * Adds no data, so no tenant marker is needed. Every constraint here fails loudly
     * on bad data rather than silently, which is the other half of that rule.
     */
    public function up(): void
    {
        Schema::create('employment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('user_id');

            $table->string('employee_code')->nullable();
            $table->unsignedBigInteger('org_unit_id');

            // Plain strings, and deliberately not a fixed list yet. The statuses this
            // product needs arrive with the processes that set them — notice and exited
            // with the exit flow, probation with onboarding — and inventing the list now
            // would be inventing the words those modules have to use.
            $table->string('employment_type');
            $table->string('employment_status');

            // Null at the top of the company. Held here rather than on the account
            // because who somebody reported to is one of the four facts an audit asks
            // about, so it cannot be a column that gets overwritten.
            $table->unsignedBigInteger('reports_to_id')->nullable();

            // Carried on every row rather than kept once on the first. A deliberate
            // duplication: reading a person as of a date stays a single-row lookup with
            // no walk back to the beginning, and a rehire is simply a fresh sequence of
            // rows with a new joining date — no extra table and no rehire concept.
            $table->date('joining_date');
            $table->date('last_working_day')->nullable();

            $table->date('effective_from');

            // Null means this is the row that is true today. The last day this row was
            // true, so a transfer on 1 April ends the previous row on 31 March.
            $table->date('effective_to')->nullable();

            $table->string('change_reason')->nullable();

            // Null where a row came from a seed or an import rather than a person.
            $table->unsignedBigInteger('recorded_by')->nullable();

            // A row entered by mistake is withdrawn, which is a different act from a
            // job change. Named for what it is rather than for deletion, which the
            // framework's own soft-delete trait allows: it reads the column name off a
            // constant on the model. That is what hides withdrawn rows from every
            // ordinary query without anybody having to remember a filter.
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->string('withdrawn_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            // Deleting an account takes its own job history with it — there is nothing
            // left for those rows to describe. Every other key restricts instead: the
            // rows saying thirty-four people reported to Rakesh must not vanish quietly
            // when his account goes, and a department with people in its history must
            // not be deletable at all.
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'org_unit_id'])
                ->references(['tenant_id', 'id'])->on('org_units');
            $table->foreign(['tenant_id', 'reports_to_id'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'recorded_by'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'withdrawn_by'])
                ->references(['tenant_id', 'id'])->on('users');

            // Reading a person as of a date, and finding somebody's direct reports.
            $table->index(['tenant_id', 'user_id', 'effective_from']);
            $table->index(['tenant_id', 'reports_to_id']);
        });

        // Exactly one row per person is the row that is true today, enforced by the
        // database rather than by application code. Withdrawn rows are excluded, or
        // withdrawing the current row would leave a withdrawn open row blocking the
        // replacement that has to take its place.
        DB::statement(
            'create unique index employment_records_one_current
               on employment_records (tenant_id, user_id)
              where effective_to is null and withdrawn_at is null'
        );

        DB::statement(
            'alter table employment_records add constraint employment_records_dates_ordered
               check (effective_to is null or effective_to >= effective_from)'
        );

        // Nobody reports to themselves. The one-step loop is refused by the database;
        // anything longer has to see the chain and is refused by the model.
        DB::statement(
            'alter table employment_records add constraint employment_records_not_self_managed
               check (reports_to_id is null or reports_to_id <> user_id)'
        );

        Rls::enable('employment_records');

        Schema::create('employee_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('user_id');

            $table->string('asset_type');
            $table->string('identifier')->nullable();

            // The department that handed it over, which is who chases it at exit.
            $table->unsignedBigInteger('org_unit_id')->nullable();

            $table->date('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();

            // The condition is recorded at each end, not once. Every other fact on this
            // table is already paired — who handed it over and when, who took it back
            // and when — and a single note would have meant whoever wrote it at return
            // overwriting what was written at issue. Being able to say what state a
            // laptop was in when it went out is the whole point in a settlement dispute.
            $table->text('issue_condition_note')->nullable();

            $table->date('returned_at')->nullable();
            $table->unsignedBigInteger('returned_to')->nullable();
            $table->text('return_condition_note')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'org_unit_id'])
                ->references(['tenant_id', 'id'])->on('org_units');
            $table->foreign(['tenant_id', 'issued_by'])
                ->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'returned_to'])
                ->references(['tenant_id', 'id'])->on('users');

            $table->index(['tenant_id', 'user_id']);
        });

        DB::statement(
            'alter table employee_assets add constraint employee_assets_dates_ordered
               check (returned_at is null or returned_at >= issued_at)'
        );

        Rls::enable('employee_assets');

        Schema::create('employee_statutory_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('user_id');

            // Rows typed by kind rather than a column per identifier, so a client in a
            // second country is rows rather than a migration. The permitted kinds are a
            // list in code on the model, which is also what keeps Aadhaar from being
            // added as a kind.
            $table->string('type');
            $table->string('country', 2)->default('IN');

            // Text, not a short string: what is stored is the encrypted form, which is
            // far longer than the identifier itself.
            $table->text('value');

            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'verified_by'])
                ->references(['tenant_id', 'id'])->on('users');

            $table->index(['tenant_id', 'user_id']);
        });

        Rls::enable('employee_statutory_ids');

        Schema::create('aadhaar_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('user_id');

            // Every Aadhaar fact this product holds, and no more: that the document was
            // seen, the four digits the masked form itself shows, and the consent that
            // was taken for it. Never the number, and never the scan.
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('last_four', 4)->nullable();

            $table->string('notice_version');
            $table->timestamp('consented_at');
            $table->string('consent_channel');
            $table->timestamp('consent_withdrawn_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            // One row per person, so there is a single row to delete when consent is
            // withdrawn and a single place a data-protection review can be pointed at.
            $table->unique(['tenant_id', 'user_id']);

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'verified_by'])
                ->references(['tenant_id', 'id'])->on('users');
        });

        // Four digits and nothing longer, so the column cannot hold a number even if
        // every check above it were removed.
        DB::statement(
            "alter table aadhaar_verifications add constraint aadhaar_verifications_last_four_digits
               check (last_four is null or last_four ~ '^[0-9]{4}$')"
        );

        Rls::enable('aadhaar_verifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('aadhaar_verifications');
        Schema::dropIfExists('employee_statutory_ids');
        Schema::dropIfExists('employee_assets');
        Schema::dropIfExists('employment_records');
    }
};
