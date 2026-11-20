<?php

use App\Models\NotificationTemplate;
use App\Models\Office;
use App\Models\ProcessCase;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;

/*
| Every screen in the signed-in area, opened in a real browser at both demo companies, by
| the administrator who can reach all of them.
|
| What it catches that the feature tests cannot: a screen whose server answer is fine but
| whose page then throws in the browser, or has a console.log left in it. Nothing else
| written to the console counts: the plugin never sees console.error or console.warn.
| And with exception handling off, a screen that fails on the server names its own fault
| here rather than drawing an error page nobody reads.
|
| The list is the panel's own routes, taken from `php artisan route:list --path=admin`
| on 18 November 2026. A screen added later has to be added here. "Raise a request" is
| on it since module 07 step 1: anybody with a job may raise their own exit, so both
| administrators can open it.
*/

beforeEach(function () {
    $this->seed([MeridianSeeder::class, VertexSeeder::class]);
});

/**
 * Every screen in the signed-in area, with the first of each record standing in for
 * the screens that open one.
 *
 * @return list<string>
 */
function everyScreenAt(string $company): array
{
    $tenant = Tenant::query()->where('slug', $company)->sole();

    return TenantContext::run($tenant, fn (): array => [
        '/admin/raise-a-request',
        '/admin/my-queue',
        '/admin/cases',
        '/admin/cases/'.ProcessCase::query()->min('id'),
        '/admin/company-settings',
        '/admin/org-units',
        '/admin/designations',
        '/admin/offices',
        '/admin/offices/'.Office::query()->min('id').'/holidays',
        '/admin/users',
        '/admin/users/create',
        '/admin/users/'.User::query()->min('id').'/edit',
        '/admin/roles',
        '/admin/roles/'.Role::query()->min('id').'/edit',
        '/admin/cover',
        '/admin/notification-templates',
        '/admin/notification-templates/'.NotificationTemplate::query()->min('id').'/edit',
        '/admin/profile',
    ]);
}

it('opens every screen at both companies with nothing broken in the browser', function (string $company, string $administrator) {
    $this->withoutExceptionHandling();

    browseAs(personAt($company, $administrator), everyScreenAt($company))
        ->assertNoSmoke();
})->with([
    'Meridian, as Chandni' => [MeridianSeeder::Slug, 'chandni'],
    'Vertex, as Meera' => [VertexSeeder::Slug, 'meera'],
]);
