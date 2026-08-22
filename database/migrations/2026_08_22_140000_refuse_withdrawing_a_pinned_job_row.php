<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A job row that a case is pinned to cannot be withdrawn — at the database, not only
     * on the model.
     *
     * The rule itself is module 01's and the model already carries it. What this adds is
     * the half that was missing, found on 22 August 2026 reviewing step 2: withdrawing is
     * a soft delete, so it reaches the table as an ordinary update and the key from
     * `cases` never sees it. A bulk update walks straight round the model, and afterwards
     * Rakesh's closed exit renders no department, no designation and no manager — which
     * is the exact failure pinning a job row exists to prevent, and the one most likely to
     * be read years later in front of a tribunal.
     *
     * A hard delete needs nothing here; the three-column key from `cases` already refuses
     * it. This covers the withdrawal, which is the route the product actually uses.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function employment_records_pinned_rows_are_not_withdrawable() returns trigger as $$
            declare
                pinned_case bigint;
            begin
                if old.withdrawn_at is null and new.withdrawn_at is not null then
                    select id into pinned_case
                      from cases
                     where subject_employment_record_id = new.id
                     limit 1;

                    if pinned_case is not null then
                        raise exception
                            'job row [%] cannot be withdrawn: case [%] is pinned to it.',
                            new.id, pinned_case;
                    end if;
                end if;

                return new;
            end;
            $$ language plpgsql
            SQL);

        DB::statement(
            'create trigger employment_records_refuse_withdrawing_a_pinned_row
               before update on employment_records
               for each row execute function employment_records_pinned_rows_are_not_withdrawable()'
        );
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists employment_records_refuse_withdrawing_a_pinned_row on employment_records');
        DB::statement('drop function if exists employment_records_pinned_rows_are_not_withdrawable()');
    }
};
