<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 09 step 3: an exit issues the experience letter and the settlement statement beside
 * the relieving letter, so the database takes all three kinds and still nothing else.
 *
 * The refusal to change or remove an issued row is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table issued_documents drop constraint issued_documents_kind_known');
        DB::statement(
            "alter table issued_documents add constraint issued_documents_kind_known
               check (kind in ('relieving_letter', 'experience_letter', 'settlement_statement'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table issued_documents drop constraint issued_documents_kind_known');
        DB::statement(
            "alter table issued_documents add constraint issued_documents_kind_known
               check (kind in ('relieving_letter'))"
        );
    }
};
