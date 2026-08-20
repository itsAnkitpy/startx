<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The second migration on the person table, which step 3 deliberately left for
     * step 4. Step 3 built only what a role grant needs to point at; this adds the
     * rest of who somebody is. Nothing about their job is here — that is dated
     * history and lives in `employment_records`.
     *
     * This migration adds and drops columns and touches no rows, so it needs no
     * tenant marker: the marker is required where a migration reads or writes data,
     * because an update with none set matches nothing and reports success.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A single full name is split into its parts while the table is empty.
            // Letters and statutory forms ask for the parts, and so does the directory
            // handoff in module 11; splitting real names later would be guesswork
            // nobody could undo. `App\Models\User::name()` reassembles the full name,
            // so nothing that displays a person had to change.
            // Only the first name is compulsory. A surname is not: people with a single
            // name are ordinary in India, and both PAN and Aadhaar accept one, so a
            // required surname would leave whoever is entering them inventing a
            // second name that then prints on a letter. Every part after the first is
            // therefore optional, and `App\Models\User::name()` skips the empty ones.
            $table->string('first_name')->after('tenant_id');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');

            // What the person is called day to day, when it is not their first name.
            $table->string('preferred_name')->nullable()->after('last_name');

            // Here rather than in the profile fields because letters and statutory
            // forms need it and it never changes.
            $table->date('date_of_birth')->nullable()->after('preferred_name');

            // The address that outlives the job. Every document owed after the last
            // working day goes here, on a signed link, because the account is closed
            // by then. Deel treats the personal address as the required one and the
            // work address as optional, for the same reason.
            $table->string('personal_email')->nullable()->after('work_email');

            // A string, never an integer. The old system stored phone numbers as
            // integers, which silently destroys a leading zero and any country code,
            // and put a single global unique index on the column — which breaks the
            // first time two client companies employ the same person. There is no
            // uniqueness on a phone number here at all.
            $table->string('personal_phone')->nullable()->after('personal_email');

            // Both fall back to the client company's own setting when not set, which
            // is why they are nullable rather than defaulted here.
            $table->string('timezone')->nullable()->after('personal_phone');
            $table->string('locale')->nullable()->after('timezone');

            // When the account stopped working, which `active` alone cannot answer.
            // The model stamps it whenever `active` goes false, so it is never a
            // column that lies about an account somebody switched off.
            $table->timestamp('deactivated_at')->nullable()->after('active');

            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->after('tenant_id');

            $table->dropColumn([
                'first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth',
                'personal_email', 'personal_phone', 'timezone', 'locale', 'deactivated_at',
            ]);
        });
    }
};
