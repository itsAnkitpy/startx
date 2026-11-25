<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 08 step 3: each claim email names the amount it told the person leaving of, so the
 * settlement statement can say when they were told.
 *
 * On the email's record rather than the amount's, because an amount is never changed after
 * it is filed and the email goes after it. The day it was filed is not the day the leaver was
 * told when the mail server was down, and that day starts their seven days to explain.
 *
 * The email names its case and its amount together, so the two cannot disagree about which
 * exit the money is on, as an amount and the clearance that filed it cannot.
 *
 * Null on every other message, and on the claim emails sent before this, which the demo
 * rebuilds.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index settlement_lines_case_and_row on settlement_lines (tenant_id, case_id, id)');

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreignId('settlement_line_id')->nullable()->after('step_sequence');

            $table->foreign(['tenant_id', 'case_id', 'settlement_line_id'], 'notification_deliveries_claim_is_on_its_case')
                ->references(['tenant_id', 'case_id', 'id'])->on('settlement_lines');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign('notification_deliveries_claim_is_on_its_case');
            $table->dropColumn('settlement_line_id');
        });

        DB::statement('drop index settlement_lines_case_and_row');
    }
};
