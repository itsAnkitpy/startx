<?php

use Illuminate\Support\Facades\DB;

// Module 01: Postgres ignores row-level security policies entirely for a superuser
// or a BYPASSRLS role, silently and without error. If this ever fails, every tenant
// isolation test in the suite is passing for the wrong reason.
it('connects as a role that cannot bypass row-level security', function () {
    $role = DB::selectOne(
        'select rolsuper::int as is_super, rolbypassrls::int as can_bypass
         from pg_roles where rolname = current_user'
    );

    expect((int) $role->is_super)->toBe(0)
        ->and((int) $role->can_bypass)->toBe(0);
});
