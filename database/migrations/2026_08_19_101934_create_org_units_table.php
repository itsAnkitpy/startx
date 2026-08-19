<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for a client company's whole structure, however many levels deep and
     * whatever the client calls the levels. A three-level client is company, business
     * line and sub-business line; a client with regions and branches is the same table
     * with different labels and no migration.
     */
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('type');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // What every client-owned table carries: something for another client-owned
            // table to point at, and keys that carry the client with them.
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'id'])
                ->on('org_units');

            // The two ways this table is read: everything directly under a unit, and
            // every unit at one level.
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'type']);
        });

        // A code is optional — a real structure carries one on its top level and none
        // below it — so uniqueness applies only where there is a code.
        DB::statement(
            'create unique index org_units_tenant_code_unique on org_units (tenant_id, code) where code is not null'
        );

        // The shortest cycle, a unit that is its own parent, is refused by the
        // database. Longer ones are refused by the model, which can see the tree.
        DB::statement(
            'alter table org_units add constraint org_units_parent_not_self check (parent_id is null or parent_id <> id)'
        );

        Rls::enable('org_units');
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
