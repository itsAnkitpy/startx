<?php

use App\Authorization\TenantPasswordTokens;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/*
| Priya has an account at Meridian and an account at Vertex, both on priya@example.test,
| which is ordinary under this design: an account belongs to one client company and the
| subdomain decides which one before anyone signs in.
|
| Laravel's own reset store keys on the address alone. Read in the framework's source:
| checking a token, checking the throttle and deleting a pending token all query the
| address with no client company named, while finding the account goes through the
| person model and so is correctly narrowed. Two things follow, and the second needs no
| attacker at all:
|
|   1. A link issued at Meridian is accepted at Vertex and changes the Vertex password.
|   2. Asking for a link at Meridian silently kills Vertex's pending link.
|
| Both are closed by putting the client company on the table and on every query.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);

    $this->meridianPriya = TenantContext::run($this->meridian, fn () => User::factory()->create([
        'first_name' => 'Priya', 'last_name' => 'Nair', 'work_email' => 'priya@example.test',
    ]));

    $this->vertexPriya = TenantContext::run($this->vertex, fn () => User::factory()->create([
        'first_name' => 'Priya', 'last_name' => 'Nair', 'work_email' => 'priya@example.test',
    ]));
});

it('uses the client-aware reset store', function () {
    expect(Password::broker()->getRepository())->toBeInstanceOf(TenantPasswordTokens::class);
});

it('refuses the framework\'s cache-backed reset store outright', function () {
    // That store carries no client company at all, so configuring it would reopen the
    // hole this whole file is about — and it would do so silently. Loud is better.
    config()->set('auth.passwords.users.driver', 'cache');

    Password::broker('users');
})->throws(InvalidArgumentException::class);

it('accepts a reset link only at the client company that issued it', function () {
    $token = TenantContext::run(
        $this->meridian,
        fn () => Password::broker()->createToken($this->meridianPriya),
    );

    $atMeridian = TenantContext::run(
        $this->meridian,
        fn () => Password::broker()->tokenExists($this->meridianPriya, $token),
    );

    $atVertex = TenantContext::run(
        $this->vertex,
        fn () => Password::broker()->tokenExists($this->vertexPriya, $token),
    );

    expect($atMeridian)->toBeTrue()->and($atVertex)->toBeFalse();
});

it('leaves one client company\'s pending link alone when a reset is asked for at another', function () {
    $vertexToken = TenantContext::run(
        $this->vertex,
        fn () => Password::broker()->createToken($this->vertexPriya),
    );

    // Priya then asks for a reset at Meridian. Nothing hostile is happening here.
    TenantContext::run(
        $this->meridian,
        fn () => Password::broker()->createToken($this->meridianPriya),
    );

    $vertexStillWorks = TenantContext::run(
        $this->vertex,
        fn () => Password::broker()->tokenExists($this->vertexPriya, $vertexToken),
    );

    expect($vertexStillWorks)->toBeTrue();
});

it('replaces a person\'s own pending link when they ask again at the same client company', function () {
    $first = TenantContext::run(
        $this->meridian,
        fn () => Password::broker()->createToken($this->meridianPriya),
    );

    $second = TenantContext::run(
        $this->meridian,
        fn () => Password::broker()->createToken($this->meridianPriya),
    );

    [$firstWorks, $secondWorks] = TenantContext::run($this->meridian, fn () => [
        Password::broker()->tokenExists($this->meridianPriya, $first),
        Password::broker()->tokenExists($this->meridianPriya, $second),
    ]);

    expect($firstWorks)->toBeFalse()->and($secondWorks)->toBeTrue();
});

it('refuses to issue a reset link with no client company in scope', function () {
    // Returning nothing here would read as "no pending link" and hide the bug.
    Password::broker()->createToken($this->meridianPriya);
})->throws(TenantContextMissing::class);

it('clears expired links across every client company at once', function () {
    TenantContext::run($this->meridian, fn () => Password::broker()->createToken($this->meridianPriya));
    TenantContext::run($this->vertex, fn () => Password::broker()->createToken($this->vertexPriya));

    $this->travel(2)->hours();

    // Housekeeping runs with no single client company in scope. Narrowed, it would match
    // nothing and report success — the silent-success shape this module keeps meeting.
    Password::broker()->getRepository()->deleteExpired();

    $meridianLeft = TenantContext::run(
        $this->meridian,
        fn () => DB::table('password_reset_tokens')->count(),
    );

    expect($meridianLeft)->toBe(0);
});
