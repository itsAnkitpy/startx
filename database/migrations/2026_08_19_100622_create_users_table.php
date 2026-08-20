<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The login half of a person. A person employed by two client companies has two
     * accounts and never notices, because each client is a different subdomain and the
     * subdomain resolves the client before anyone authenticates.
     *
     * Dated job history, assets and statutory identifiers arrive in step 4 as a second
     * migration on this table plus new ones. Only what a role grant needs to point at
     * is here.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('work_email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            // Something for a role grant to point at with a key that carries the client.
            $table->unique(['tenant_id', 'id']);

            // One work address per account inside one client company, and it stays with
            // the account after the person leaves — there is no active-only exception.
            // A rehire is fresh employment rows on this same account, so the address
            // never needs releasing, and looking a returning person up by it finds one
            // account rather than two.
            $table->unique(['tenant_id', 'work_email']);
        });

        Rls::enable('users');

        // Laravel's own reset table keys on the address alone, and its token check,
        // its throttle check and its delete all query the address with no client
        // company named. So a token issued at Meridian validates against a Vertex
        // account on the same address and resets that one's password, and a reset
        // requested at Meridian silently deletes Vertex's pending link.
        //
        // `sessions` is deliberately left alone: a session names one account, and that
        // account already belongs to one client company.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained();
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'email']);
        });

        Rls::enable('password_reset_tokens');

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
