<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two lists a client company maintains, and the four columns on the job row that
     * point at them.
     *
     * Two lists rather than the four the plan first named: cost centres are dropped
     * because nothing in this product reads one, and letter kinds stay a fixed list in
     * code so module 09 can guarantee a template cannot name a token that does not exist.
     *
     * Neither list is seeded from anything. One company's designations are one company's
     * vocabulary, and a client typing their own handful is quicker than correcting
     * somebody else's.
     */
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            $table->string('name');

            // Retired rather than deleted. Job rows point at these rows, so a delete
            // would take a designation out of somebody's history; switching it off takes
            // it out of the picker and leaves every record that named it readable.
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        $this->nameRules('designations');

        Rls::enable('designations');

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            $table->string('name');

            // ISO 3166-1 alpha-2.
            $table->string('country', 2)->default('IN');

            // The state or province as an ISO 3166-2 code — 'IN-HP', 'US-CA'. The one
            // piece of geography this product genuinely needs, because professional tax
            // in India follows where a person works rather than where the company is
            // registered, so module 11's payroll handoff has to be able to name it in a
            // form payroll recognises. Nullable because plenty of countries have no
            // state: the United Kingdom is one.
            $table->string('state_code', 6)->nullable();

            // One free-text block rather than city, state and postcode columns. Which
            // address fields exist at all changes by country — the United Arab Emirates
            // has no postcode, Ireland's identifies a single building — so three columns
            // would be an India-shaped table. Nothing reads this apart from the rare
            // letter that prints an address, and printing does not need it split.
            $table->text('address_block')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        $this->nameRules('offices');

        // The column is named for an ISO 3166-2 code, so it holds one or nothing. Without
        // this, 'Himachal Pradesh' sits in it happily and module 11's payroll handoff is
        // the thing that discovers it. The country half of the code has to match the
        // country beside it, which is the other half of what the code means.
        DB::statement(
            "alter table offices add constraint offices_state_is_iso_3166_2
               check (state_code is null
                      or (state_code ~ '^[A-Z]{2}-[A-Z0-9]{1,3}$' and left(state_code, 2) = country))"
        );

        DB::statement(
            "alter table offices add constraint offices_country_is_iso_3166_1
               check (country ~ '^[A-Z]{2}$')"
        );

        Rls::enable('offices');

        Schema::table('employment_records', function (Blueprint $table) {
            $table->unsignedBigInteger('designation_id')->nullable()->after('org_unit_id');
            $table->unsignedBigInteger('office_id')->nullable()->after('designation_id');

            // The words beside the link, and the whole point of this step. Freezing the
            // link alone freezes *which* list row was chosen, not *what that row said* —
            // so a client tidying "Sr. Manager" into "Senior Manager" would rewrite the
            // designation on every case already closed, and correcting an office's state
            // would rewrite which state every past job row claims the person worked in.
            //
            // The copy cannot drift from the row it sits on, because job rows are only
            // ever inserted: the row *is* the version. That is why the same copy was
            // refused on the case, where the case does get updated.
            $table->string('recorded_designation_name')->nullable()->after('office_id');

            // The country as well as the state, because the state alone freezes nothing
            // for an office outside India. A client reusing a closed London entry for a
            // Dublin one — which is what people do instead of switching one off and
            // adding another — would otherwise move every job row that named it to
            // Ireland, retrospectively. Every office has a country, which is also what
            // lets the database demand the pair below.
            $table->string('recorded_office_country', 2)->nullable()->after('recorded_designation_name');
            $table->string('recorded_office_state_code', 6)->nullable()->after('recorded_office_country');

            $table->foreign(['tenant_id', 'designation_id'])
                ->references(['tenant_id', 'id'])->on('designations');
            $table->foreign(['tenant_id', 'office_id'])
                ->references(['tenant_id', 'id'])->on('offices');
        });

        // A link with no words beside it is the failure this step exists to prevent, so
        // the database refuses it rather than trusting every future write path to
        // remember.
        DB::statement(
            'alter table employment_records add constraint employment_records_designation_name_frozen
               check ((designation_id is null) = (recorded_designation_name is null))'
        );

        // The same rule for the office, which the country is what makes possible: the
        // state cannot carry it, because an office in a country with no states
        // legitimately has none to freeze. The second half refuses a frozen state that
        // disagrees with the frozen country — `is not distinct from` rather than `=`
        // because a check whose expression comes out null passes, so `left(state, 2) =
        // null` would wave a state with no country beside it straight through.
        DB::statement(
            'alter table employment_records add constraint employment_records_office_copy_frozen
               check ((office_id is null) = (recorded_office_country is null)
                      and (recorded_office_state_code is null
                           or left(recorded_office_state_code, 2)
                              is not distinct from recorded_office_country))'
        );
    }

    /**
     * The two rules a name on either list obeys.
     *
     * **One name per client company, ignoring case, counting switched-off rows.** Ignoring
     * case because "Senior Manager" and "senior manager" are the same designation to
     * everybody except a plain unique index. Counting switched-off rows because a client
     * re-adding a name they retired should be sent back to the row their own history
     * already points at, rather than opening a second row beside it under the same name
     * and splitting one designation across two.
     *
     * **A name is not blank and carries no padding.** Added 20 August 2026 on reviewing
     * the finished code, which found the rule above bypassed by a space: lowercasing does
     * not trim, so "Senior Manager" and "Senior Manager " were two rows, which is the
     * outcome the rule exists to prevent — and the padding travels, because the job row
     * freezes the name and a report grouped by it then splits one designation in two. A
     * blank name got in the same way, and travelled further: the job row froze an empty
     * name, the rule that a link must carry its name accepted it because an empty string
     * is not nothing, and an empty designation reached the directory sync and the letters.
     *
     * Refusing an untrimmed name rather than quietly trimming it, which is why one check
     * covers both and no model code is needed. A form never sees this — Laravel trims
     * every submitted field and turns an empty one into nothing, which the not-null column
     * already refuses. What reaches here is a seeder or a bulk paste, and a dirty file is
     * worth being told about rather than being silently cleaned up.
     */
    private function nameRules(string $table): void
    {
        DB::statement("create unique index {$table}_name_per_tenant on {$table} (tenant_id, lower(name))");

        DB::statement(
            "alter table {$table} add constraint {$table}_name_not_blank_or_padded
               check (name = btrim(name) and name <> '')"
        );
    }

    public function down(): void
    {
        DB::statement('alter table employment_records drop constraint employment_records_designation_name_frozen');
        DB::statement('alter table employment_records drop constraint employment_records_office_copy_frozen');

        Schema::table('employment_records', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'designation_id']);
            $table->dropForeign(['tenant_id', 'office_id']);
            $table->dropColumn([
                'designation_id', 'office_id', 'recorded_designation_name',
                'recorded_office_country', 'recorded_office_state_code',
            ]);
        });

        Schema::dropIfExists('offices');
        Schema::dropIfExists('designations');
    }
};
