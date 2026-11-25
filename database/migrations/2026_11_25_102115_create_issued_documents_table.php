<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 09 step 1: every document issued on a case is a row naming its file, and the file's
 * fingerprint.
 *
 * The PDF is the record. The row says where it is, what it is a hash of, who issued it and
 * when, and the database refuses to change any of that or to remove the row.
 *
 * The whole row is refused, as a settlement line is, so a column added later is fixed
 * without anybody having to remember it. The step that adds the recipient's answer opens
 * those columns and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('case_id');
            $table->string('kind');

            // The disk is kept beside the path, as an attached file's is, so moving where
            // new documents go never loses the old ones.
            $table->string('disk');
            $table->string('path');
            $table->char('sha256', 64);

            $table->unsignedBigInteger('issued_by');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'case_id'])->references(['tenant_id', 'id'])->on('cases');
            $table->foreign(['tenant_id', 'issued_by'])->references(['tenant_id', 'id'])->on('users');
        });

        DB::statement(
            "alter table issued_documents add constraint issued_documents_kind_known
               check (kind in ('relieving_letter'))"
        );

        DB::statement(<<<'SQL'
            create or replace function issued_documents_record_is_fixed() returns trigger as $$
            begin
                raise exception 'issued_documents keeps what was issued; % refused.', lower(tg_op);
            end;
            $$ language plpgsql
            SQL);

        DB::statement(
            'create trigger issued_documents_refuse_change before update or delete on issued_documents
               for each row execute function issued_documents_record_is_fixed()'
        );

        DB::statement(
            'create trigger issued_documents_refuse_truncate before truncate on issued_documents
               for each statement execute function issued_documents_record_is_fixed()'
        );

        Rls::enable('issued_documents');
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
        DB::statement('drop function if exists issued_documents_record_is_fixed()');
    }
};
