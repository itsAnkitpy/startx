<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The lock that makes a running case safe: once a version is published, its steps
     * are frozen for good, and one process has exactly one live version at a time.
     *
     * Both are written at the database rather than in the publish path, for the same
     * reason the history table's rule is: the whole protection a case has is that the
     * version it points at cannot change, and a protection that only application code
     * applies is one an import, a console command or a future screen can walk round.
     */
    public function up(): void
    {
        // Two rows of the same process both reading `published` would leave the version
        // a new case opens on decided by whichever the database happened to return
        // first — Rakesh's exit and Chandni's running different approval chains, with
        // nothing on either screen to explain why.
        DB::statement(
            "create unique index process_templates_one_published_version
               on process_templates (tenant_id, key)
              where status = 'published'"
        );

        // Archived is frozen too. A version cases still point at has to stay exactly as
        // it was for as long as those cases are readable, which is for good.
        DB::statement(<<<'SQL'
            create or replace function process_steps_are_frozen_once_live() returns trigger as $$
            declare
                current_status text;
            begin
                select status into current_status
                  from process_templates
                 where id = coalesce(new.template_id, old.template_id);

                if current_status in ('published', 'archived') then
                    raise exception
                        'process version [%] is % and its steps cannot be changed; publish a new version instead.',
                        coalesce(new.template_id, old.template_id), current_status;
                end if;

                return coalesce(new, old);
            end;
            $$ language plpgsql
            SQL);

        // Delete as well as insert and update, since removing a step from a live version
        // changes it exactly as much as renaming one does.
        //
        // This does not make the version itself undeletable, checked rather than assumed:
        // deleting the version cascades onto its steps, and by the time this trigger runs
        // the parent row is already gone, so it reads no status and allows the row
        // through. What protects a version somebody is running on is the key from `cases`,
        // which refuses to let it go while a case points at it.
        DB::statement(
            'create trigger process_steps_refuse_change_once_live
               before insert or update or delete on process_steps
               for each row execute function process_steps_are_frozen_once_live()'
        );
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists process_steps_refuse_change_once_live on process_steps');
        DB::statement('drop function if exists process_steps_are_frozen_once_live()');
        DB::statement('drop index if exists process_templates_one_published_version');
    }
};
