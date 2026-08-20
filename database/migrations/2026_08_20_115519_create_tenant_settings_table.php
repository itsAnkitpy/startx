<?php

use App\Settings\Settings;
use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per client company per switch — the storage half of "a new client is
     * configuration, not a fork".
     *
     * Not a free-form bag. Every name is declared in code ({@see Settings}) with its
     * kind, its default, the rule a value must pass and a line of help, so a name
     * nothing declares is refused rather than stored, a missing row means the declared
     * default rather than null, and module 12 can generate the screen from the
     * declarations instead of hand-writing one control per switch.
     *
     * The value is jsonb so that a switch holding a number, a flag or a word needs no
     * column of its own. What a switch may hold is a question for code, not for the
     * column type.
     */
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // The permanent internal name, matching a declaration in code. Never
            // shown to a client — the help line on the declaration is what they read.
            $table->string('key');
            // Nullable because a switch may legitimately hold nothing — the stand-in
            // for a vacant role, for instance, where the honest answer is nobody. A
            // declaration whose rule does not say `nullable` refuses null before it
            // gets here, so the column does not need to repeat the check. A missing
            // row and a stored null are different answers: the first means the client
            // has never touched it, the second that they chose nothing.
            $table->jsonb('value')->nullable();

            // Who last changed it. Nullable because a seed or a scheduled pass has
            // nobody signed in, and that is a real answer rather than a missing one.
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key']);

            // Composite, carrying the client company, because Postgres bypasses
            // row-level policies for referential-integrity checks — a plain key here
            // would let one client's row name another client's person.
            // No delete action, matching the two keys of the same shape on the job
            // table. `nullOnDelete` was tried here first and proved wrong against this
            // database on 20 August 2026: Postgres nulls every column of a composite
            // key, `tenant_id` included, so deleting a person raised a not-null
            // violation naming this table — a confusing failure on a table nobody was
            // touching. Without the action the delete is refused for the real reason.
            $table->foreign(['tenant_id', 'updated_by'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });

        Rls::enable('tenant_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
