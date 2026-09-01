<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;

/*
| What a client sees the moment they sign in.
|
| Three claims, and each one failed silently before this: signing in put everybody on an
| empty page, that page carried the framework's own name and a link to its website, and
| the menu was one flat list about to hold ten entries.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);

    $this->meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
});

/** Somebody from the demo company, by first name. */
function meridianPerson(string $first): User
{
    return User::query()->where('work_email', $first.'@meridian.test')->sole();
}

it('sends somebody signing in to what is waiting on them', function () {
    $anjali = TenantContext::run($this->meridian, fn () => meridianPerson('anjali'));

    // Anjali holds no role at all, so this is also the check that the landing page is
    // one everybody can reach rather than one only an administrator can.
    $this->actingAs($anjali)
        ->get('http://meridian.localhost/admin')
        ->assertRedirect('http://meridian.localhost/admin/my-queue');
});

it('shows a client no framework branding on the page they land on', function () {
    $chandni = TenantContext::run($this->meridian, fn () => meridianPerson('chandni'));

    // Followed from the front door rather than asked for by name: the box carrying the
    // framework's logo, its website and its version number sat on the page `/admin` used
    // to open, so asking for any other page could never have caught it.
    $this->actingAs($chandni)
        ->followingRedirects()
        ->get('http://meridian.localhost/admin')
        ->assertOk()
        ->assertDontSee('filamentphp.com');
});

it('sorts the menu into what you do and how the company is set up, in that order', function () {
    $chandni = TenantContext::run($this->meridian, fn () => meridianPerson('chandni'));

    $this->actingAs($chandni)
        ->get('http://meridian.localhost/admin/my-queue')
        ->assertOk()
        // The order is the half that matters and the half nothing else would catch. Both
        // headings appear whether or not the panel names them, because each screen says
        // which heading it belongs under — but which comes first is otherwise decided by
        // whichever screen the framework happens to find first, and the first heading's
        // first screen is where everybody lands.
        ->assertSeeInOrder(['Your work', 'My queue', 'Company setup', 'Settings']);
});
