<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 09 step 2: what a client company prints its letters with, one row per company.
 *
 * A letter's wording left empty is our starter wording, read from code, so a company created
 * tomorrow has both letters without anything seeded and no row until somebody saves. The
 * pictures are paths on the documents disk, set only through the screen's own upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_setups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained();

            // Named as the letter kinds are, so the kind is the column.
            $table->text('relieving_letter')->nullable();
            $table->text('experience_letter')->nullable();

            $table->string('letterhead')->nullable();
            $table->string('footer')->nullable();
            $table->string('signature')->nullable();
            $table->string('signed_by')->nullable();
            $table->string('signed_as')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Rls::enable('letter_setups');
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_setups');
    }
};
