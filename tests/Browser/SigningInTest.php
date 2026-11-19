<?php

use Database\Seeders\MeridianSeeder;
use Database\Seeders\VertexSeeder;

/*
| Module 01 in a real browser: somebody signs in at their own company's address, and one
| company's address never shows another company's people.
*/

beforeEach(function () {
    $this->seed([MeridianSeeder::class, VertexSeeder::class]);
});

it('signs Anjali in at Meridian and puts her on what is waiting on her', function () {
    pest()->browser()->withHost(MeridianSeeder::Slug.'.'.config('tenancy.central_domain'));

    visit('/admin/login')
        ->assertSee('Meridian Logistics')
        ->fill('[id$="email"]', 'anjali@meridian.test')
        ->fill('[id$="password"]', MeridianSeeder::Password)
        ->press('[type="submit"]')
        ->assertPathIs('/admin/my-queue')
        ->assertSee('Nothing is waiting on you.')
        ->assertNoSmoke();
});

it('shows nobody from Meridian on Vertex\'s people screen', function () {
    browseAs(personAt(VertexSeeder::Slug, 'meera'), '/admin/users')
        ->assertSee('Neha Deshpande')
        ->assertDontSee('Anjali Rao')
        ->assertDontSee('Chandni Verma');
});
