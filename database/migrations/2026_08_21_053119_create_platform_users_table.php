<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SummerHill's own people. Deliberately not the `users` table: our people are not
     * employed by any client company, so an account here has no `tenant_id` to carry
     * and no row-level security to obey — there is no client whose rows it could be
     * confused with.
     *
     * Keeping it separate is also what makes the two sign-ins genuinely separate. The
     * platform area names this table through its own authentication guard, so a client's
     * employee cannot reach our area even with the right password, because our area
     * never reads their table.
     *
     * Sessions are shared with the client area's table on purpose: a session names one
     * account and one guard, and Laravel keeps the two guards' keys apart inside it.
     */
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
};
