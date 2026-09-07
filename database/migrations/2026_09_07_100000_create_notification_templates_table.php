<?php

use App\Authorization\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One client company's own wording for each message this product sends, plus the
     * action that lets somebody change it.
     *
     * **Two columns and a key, where the plan named six.** `channel` is gone: email is
     * the only channel at launch and the plan says no other is built until asked for, so
     * the column would hold the same word on every row and imply a choice a client does
     * not have. What actually needs a channel is the record of a message going out, which
     * is a different table in the next step. `name` and `bypasses_digest` are gone for a
     * different reason — they are ours rather than the client's, so they live in code
     * beside the wording we ship, where a column nobody may write to cannot drift from
     * the code that decides the answer. And `active` is gone because a switch that
     * silenced a message is the one thing this module refuses outright.
     *
     * @see TemplateCatalogue for everything about a message that is not the client's
     */
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // Which of our messages this row supplies the words for. Never editable
            // afterwards; the model refuses moving a row onto a different message.
            $table->string('key');

            $table->string('subject');
            $table->text('body');

            // Whether this client wrote these words themselves, recorded rather than
            // worked out by comparing them with the wording we ship. Comparing was
            // wrong the moment we improve a sentence of our own: every untouched row at
            // every client would start claiming the client wrote it, and would offer
            // them a way back out of words they never chose. It is also what lets a
            // later improvement to our own wording reach the clients still on it and
            // leave alone the ones who have written their own.
            $table->boolean('reworded')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        // One row per message per client. Without it a client can end up with two sets of
        // words for the same message and no way to tell which one goes out — and the
        // seeder that fills a gap for an existing client would add a second copy every
        // time it ran rather than leaving a reworded message alone.
        DB::statement(
            'create unique index notification_templates_one_per_message
               on notification_templates (tenant_id, key)'
        );

        // A blank subject line or a blank body is a message that arrives empty, and a
        // padded subject line is one that reads with a gap before the first word in
        // somebody's inbox. The body is checked for being blank only, not for padding:
        // the wording we ship ends in a newline on purpose, so requiring a trimmed body
        // would refuse our own rows.
        DB::statement(
            "alter table notification_templates add constraint notification_templates_words_not_blank
               check (subject = btrim(subject) and subject <> '' and btrim(body) <> '')"
        );

        Rls::enable('notification_templates');

        // Every client company that already exists gets the catalogue now, which is the
        // rule Ankit settled on 6 September 2026: a client who signed up before a message
        // existed must not silently never receive it. A client created from here on gets
        // it while the company is being set up.
        //
        // One company at a time rather than one statement across all of them, because the
        // rows are walled and the catalogue's own seeder is what knows the wording — and
        // running it per company is what makes it reusable by the migration that adds the
        // thirteenth message later.
        Tenant::query()->get()->each(function (Tenant $tenant): void {
            TenantContext::run($tenant, function (): void {
                TemplateCatalogue::seed();
            });
        });

        // The fourth time this cost is paid, and for the same reason each time: a role's
        // actions are rows, so an action added by a later module reaches nobody on its
        // own. Across every client company at once through the audited path that exists
        // for this and logs itself, and written as one statement rather than through the
        // models, because those stamp the company onto a new row from whichever one is in
        // scope and here there is none.
        TenantContext::cross(function (): void {
            DB::statement(
                'insert into role_permissions (tenant_id, role_id, permission, created_at, updated_at)
                   select roles.tenant_id, roles.id, ?, now(), now()
                     from roles
                    where roles.key = ?
                 on conflict (tenant_id, role_id, permission) do nothing',
                [Permission::ManageNotificationTemplate, Role::AdministratorKey],
            );
        }, 'granting the message wording action to every client administrator');
    }

    /**
     * The action comes off only the Administrator roles it was put on, rather than off
     * every role holding it — an earlier rollback in this project took an action off a
     * role a client had ticked themselves, and that was noted as worth not repeating.
     */
    public function down(): void
    {
        TenantContext::cross(function (): void {
            DB::statement(
                'delete from role_permissions
                       using roles
                      where role_permissions.role_id = roles.id
                        and role_permissions.tenant_id = roles.tenant_id
                        and roles.key = ?
                        and role_permissions.permission = ?',
                [Role::AdministratorKey, Permission::ManageNotificationTemplate],
            );
        }, 'removing the message wording action from every client administrator');

        Schema::dropIfExists('notification_templates');
    }
};
