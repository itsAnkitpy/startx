<?php

use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * Module 08 step 2: one new message for every client who already exists, telling the person
 * leaving that a claim against them changed or was dropped. Added only where missing, so
 * nothing a client wrote is touched; the wording stays on the way down, as in every
 * migration of module 06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->get()->each(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => TemplateCatalogue::seed()));
    }

    public function down(): void {}
};
