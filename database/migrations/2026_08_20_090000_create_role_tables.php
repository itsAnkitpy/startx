<?php

use App\Authorization\Permission;
use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles, the list of actions each one may perform, and who holds which role over
     * which part of the structure. Three tables, all owned by a client company, so
     * Meridian renaming a role cannot affect Vertex.
     *
     * The action names themselves are constants in code ({@see Permission}),
     * not rows: a name only means something if there is code behind it doing the thing,
     * so a client-invented name could never do anything. What a client combines freely
     * is which of the fixed actions each of their roles may perform.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // `key` is the permanent internal name and is never edited — it is what a
            // seeded process template points at, and what the two-administrator rule
            // names. `name` is the label the client renames at will, which is why roles
            // are rows rather than an enum in code.
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key']);
        });

        Rls::enable('roles');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('role_id');
            $table->string('permission');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'role_id', 'permission']);
            $table->foreign(['tenant_id', 'role_id'])
                ->references(['tenant_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
        });

        Rls::enable('role_permissions');

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');

            // No org unit means the grant covers the whole client company. Set to a unit,
            // the grant covers that unit, and reaches down to everything below it only
            // when told to — otherwise "HR head for this one branch" and "finance
            // controller for this business line and everything under it" are the same row.
            $table->unsignedBigInteger('org_unit_id')->nullable();
            $table->boolean('includes_descendants')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'role_id'])
                ->references(['tenant_id', 'id'])
                ->on('roles')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'org_unit_id'])
                ->references(['tenant_id', 'id'])
                ->on('org_units')
                ->cascadeOnDelete();

            // How the resolver reads this table: every grant one person holds.
            $table->index(['tenant_id', 'user_id']);
        });

        // The same person may hold the same role over two different units, but not twice
        // over the same one. NULLS NOT DISTINCT is what makes that cover the whole-company
        // grant too — Postgres would otherwise treat each null org unit as a different
        // value and allow the row twice. Postgres 15 and above; this database is 18.
        DB::statement(
            'create unique index role_assignments_grant_unique
               on role_assignments (tenant_id, user_id, role_id, org_unit_id) nulls not distinct'
        );

        Rls::enable('role_assignments');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
