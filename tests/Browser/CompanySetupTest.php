<?php

use App\Models\Office;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\MeridianSeeder;

/*
| Module 05.2 in a real browser: Chandni adds a holiday to an office's calendar and sets
| cover for somebody going away. Both are Filament dialogs, which is what the browser is
| here for — the list that opens as you type and the date box live only in the page.
*/

beforeEach(function () {
    $this->seed(MeridianSeeder::class);
});

it('lets Chandni add a holiday to the Shimla office calendar', function () {
    $meridian = Tenant::query()->where('slug', MeridianSeeder::Slug)->sole();
    $shimla = TenantContext::run($meridian, fn (): Office => Office::query()->where('name', 'Shimla office')->sole());

    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), "/admin/offices/{$shimla->getKey()}/holidays")
        ->click('Add a date')
        ->fill('.fi-modal-window [id$=".date"]', now()->addMonth()->format('Y-m-d'))
        ->fill('.fi-modal-window [id$=".name"]', 'Shimla Foundation Day')
        ->click('.fi-modal-window [type="submit"]')
        ->assertVisible('.fi-ta-row:has-text("Shimla Foundation Day")')
        ->assertNoSmoke();
});

it('lets Chandni set Rohit to cover Deepak\'s exits while he is away', function () {
    browseAs(personAt(MeridianSeeder::Slug, 'chandni'), '/admin/cover')
        ->click('Set cover')
        ->click('.fi-fo-field:has-text("Who is away") .fi-select-input-btn')
        ->click('.fi-fo-field:has-text("Who is away") [role="option"]:has-text("Deepak Iyer")')
        ->click('.fi-fo-field:has-text("Who holds their approvals") .fi-select-input-btn')
        ->click('.fi-fo-field:has-text("Who holds their approvals") [role="option"]:has-text("Rohit Menon")')
        ->click('.fi-fo-field:has-text("Which process") .fi-select-input-btn')
        ->click('.fi-fo-field:has-text("Which process") [role="option"][data-value="exit"]')
        ->fill('.fi-modal-window [id$=".effective_from"]', now()->addDay()->format('Y-m-d'))
        ->fill('.fi-modal-window [id$=".effective_to"]', now()->addDays(8)->format('Y-m-d'))
        ->click('.fi-modal-window [type="submit"]')
        ->assertVisible('.fi-ta-row:has-text("Deepak Iyer"):has-text("Rohit Menon")')
        ->assertNoSmoke();
});
