<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;

/*
| An account belongs to one client company, like every other row. Priya employed by
| both Meridian and Vertex has two accounts and never notices, because each client is a
| different subdomain and the subdomain decides which company a request belongs to
| before anyone signs in.
|
| A refused insert abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own — so each expected refusal gets a test to itself.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
});

it('lets the same work address hold an account at two client companies', function () {
    $meridianPriya = TenantContext::run($this->meridian, fn () => User::create([
        'name' => 'Priya Nair',
        'work_email' => 'priya@example.test',
        'password' => 'correct-horse',
    ]));

    $vertexPriya = TenantContext::run($this->vertex, fn () => User::create([
        'name' => 'Priya Nair',
        'work_email' => 'priya@example.test',
        'password' => 'correct-horse',
    ]));

    expect($meridianPriya->getKey())->not->toBe($vertexPriya->getKey());

    // And neither account can see the other company's people.
    TenantContext::run($this->meridian, function () use ($vertexPriya) {
        expect(User::query()->count())->toBe(1)
            ->and(User::query()->find($vertexPriya->getKey()))->toBeNull();
    });
});

it('refuses a second account on the same work address inside one client company', function () {
    TenantContext::run($this->meridian, function () {
        User::create(['name' => 'Priya Nair', 'work_email' => 'priya@example.test', 'password' => 'x']);

        User::create(['name' => 'Priya Menon', 'work_email' => 'priya@example.test', 'password' => 'x']);
    });
})->throws(QueryException::class);

it('keeps a departed person holding their work address', function () {
    // The address stays with the account after the last working day. What other products
    // actually suffer is duplicate people: a returning person's earlier record cannot be
    // found, so somebody creates a second one. Here a rehire is fresh employment rows on
    // this same account, so the address never needs releasing.
    TenantContext::run($this->meridian, function () {
        User::factory()->inactive()->create(['name' => 'Rakesh Iyer', 'work_email' => 'rakesh@example.test']);

        User::create(['name' => 'Rakesh Sharma', 'work_email' => 'rakesh@example.test', 'password' => 'x']);
    });
})->throws(QueryException::class);

it('signs in on the work address', function () {
    TenantContext::run($this->meridian, function () {
        User::factory()->create(['work_email' => 'anjali@example.test']);

        expect(Auth::attempt(['work_email' => 'anjali@example.test', 'password' => 'password']))->toBeTrue();
    });
});

it('will not authenticate a leaver, even with the right password', function () {
    // Not only refused at the panel door: refused by authentication itself, so any route
    // added later cannot let a leaver in by forgetting to check. Rakesh's password is
    // still correct — it is his account that has ended.
    TenantContext::run($this->meridian, function () {
        User::factory()->inactive()->create(['work_email' => 'rakesh@example.test']);

        expect(Auth::attempt(['work_email' => 'rakesh@example.test', 'password' => 'password']))->toBeFalse();
    });
});

it('drops a signed-in person the moment their account is deactivated', function () {
    // What an exit does on a last working day. Their session is not hunted down; the
    // account simply stops being found on the next request.
    TenantContext::run($this->meridian, function () {
        $rakesh = User::factory()->create(['work_email' => 'rakesh@example.test']);

        expect(Auth::loginUsingId($rakesh->getKey()))->not->toBeFalse();

        $rakesh->update(['active' => false]);

        expect(Auth::getProvider()->retrieveById($rakesh->getKey()))->toBeNull();
    });
});

it('refuses the panel to an account past its last working day', function () {
    TenantContext::run($this->meridian, function () {
        $panel = filament()->getPanel('admin');

        $priya = User::factory()->create();
        $rakesh = User::factory()->inactive()->create();

        expect($priya->canAccessPanel($panel))->toBeTrue()
            ->and($rakesh->canAccessPanel($panel))->toBeFalse();
    });
});

it('sends a person their mail and their reset links at their work address', function () {
    TenantContext::run($this->meridian, function () {
        $priya = User::factory()->create(['work_email' => 'priya@example.test']);

        expect($priya->getEmailForPasswordReset())->toBe('priya@example.test')
            ->and($priya->routeNotificationForMail())->toBe('priya@example.test');
    });
});
