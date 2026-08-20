<?php

use App\Filament\Auth\Login;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Livewire\Livewire;

/*
| The address column is `work_email`, not `email`, so the sign-in page maps its own field
| onto it. That mapping is one line and would break silently — the page would simply
| refuse every correct password — so it gets a test through the real page rather than
| through the guard.
|
| A leaver is refused here too, and Filament refuses before creating any session at all,
| so no cookie is ever issued for an account that has ended.
*/

it('signs a person in through the panel page on their work address', function () {
    $meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    TenantContext::run($meridian, function () {
        $anjali = User::factory()->create(['work_email' => 'anjali@example.test']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'anjali@example.test', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        expect(auth()->id())->toBe($anjali->getKey());
    });
});

it('refuses a leaver at the panel page, with no session created', function () {
    $meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    TenantContext::run($meridian, function () {
        User::factory()->inactive()->create(['work_email' => 'rakesh@example.test']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'rakesh@example.test', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasFormErrors();

        expect(auth()->check())->toBeFalse();
    });
});
