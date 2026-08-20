<?php

use App\Http\Middleware\BindTenantToRequest;
use App\Models\Tenant;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\CountFixturesJob;
use Tests\Fixtures\WallFixture;

/*
| The wall has two halves that must always point at the same client company: the
| Eloquent scope in the application, and the Postgres policy in the database. This
| file covers the object that sets both, on each way into the system.
*/

beforeEach(function () {
    createWalledFixtureTables();

    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);

    TenantContext::run($this->meridian, fn () => WallFixture::create(['name' => 'Meridian head office']));
    TenantContext::run($this->vertex, fn () => WallFixture::create(['name' => 'Vertex head office']));
    TenantContext::run($this->vertex, fn () => WallFixture::create(['name' => 'Vertex depot']));
});

function markerOnConnection(): ?string
{
    $value = DB::selectOne("select nullif(current_setting('".Rls::TENANT_MARKER."', true), '') as tenant_id");

    return $value->tenant_id;
}

it('scopes an ordinary Eloquent query to the client company in scope', function () {
    expect(TenantContext::run($this->meridian, fn () => WallFixture::query()->count()))->toBe(1)
        ->and(TenantContext::run($this->vertex, fn () => WallFixture::query()->count()))->toBe(2);
});

it('refuses to read a client-owned table when no client company is in scope', function () {
    expect(fn () => WallFixture::query()->count())
        ->toThrow(TenantContextMissing::class, 'No tenant is in scope');
});

it('refuses to write a client-owned record when no client company is in scope', function () {
    expect(fn () => WallFixture::create(['name' => 'Belongs to nobody']))
        ->toThrow(TenantContextMissing::class);
});

it('stamps a new record with the client company in scope', function () {
    $record = TenantContext::run($this->meridian, fn () => WallFixture::create(['name' => 'Meridian depot']));

    expect($record->tenant_id)->toBe($this->meridian->getKey());
});

it('sets the database marker while a client company is in scope and clears it afterwards', function () {
    TenantContext::run($this->meridian, function () {
        expect(markerOnConnection())->toBe((string) $this->meridian->getKey());
    });

    expect(markerOnConnection())->toBeNull()
        ->and(TenantContext::id())->toBeNull();
});

it('restores the surrounding client company after a nested one', function () {
    TenantContext::run($this->meridian, function () {
        TenantContext::run($this->vertex, function () {
            expect(TenantContext::id())->toBe($this->vertex->getKey())
                ->and(markerOnConnection())->toBe((string) $this->vertex->getKey());
        });

        expect(TenantContext::id())->toBe($this->meridian->getKey())
            ->and(markerOnConnection())->toBe((string) $this->meridian->getKey());
    });
});

it('keeps a web request\'s marker alive across a transaction inside the request', function () {
    // This is why a web request uses a plain SET. A screen render has no transaction
    // around it, so a marker written to live only inside one would be gone before the
    // first query and every table would come back empty.
    TenantContext::applyWebRequest($this->meridian->getKey());

    $counted = DB::transaction(fn () => WallFixture::query()->count());

    expect($counted)->toBe(1)
        ->and(markerOnConnection())->toBe((string) $this->meridian->getKey());

    TenantContext::resetWebRequest();

    expect(markerOnConnection())->toBeNull();
});

it('reads the client company from the subdomain, before anyone has signed in', function () {
    $middleware = new BindTenantToRequest;

    $middleware->handle(
        Request::create('http://vertex.localhost/admin'),
        function () {
            expect(TenantContext::id())->toBe($this->vertex->getKey())
                ->and(WallFixture::query()->count())->toBe(2);

            return response('ok');
        }
    );
});

it('leaves no client company in scope for an unknown or inactive subdomain', function () {
    $middleware = new BindTenantToRequest;

    $this->vertex->update(['active' => false]);

    foreach (['http://nobody.localhost/admin', 'http://vertex.localhost/admin', 'http://localhost/admin'] as $url) {
        $middleware->handle(Request::create($url), function () {
            expect(TenantContext::id())->toBeNull();

            return response('ok');
        });

        expect(TenantContext::id())->toBeNull();
    }
});

it('tells a visitor at an address we do not have that we do not have it', function () {
    $reached = false;

    $response = (new BindTenantToRequest)->handle(
        Request::create('http://nobody.localhost/admin'),
        function () use (&$reached) {
            $reached = true;

            return response('ok');
        }
    );

    // Stopped here rather than carrying on to a sign-in form with no company behind it,
    // which is what a visitor used to get: a page they could type into and never pass.
    expect($reached)->toBeFalse()
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toContain('No StartX company at this address')
        ->and($response->getContent())->toContain('Check the address with your HR team');
});

it('tells a switched-off client company something different, and never says why', function () {
    // Anjali's company is locked out for non-payment. Before this she saw exactly what a
    // stranger guessing addresses saw, so the product looked broken and she rang support.
    $this->vertex->update(['active' => false]);

    $response = (new BindTenantToRequest)->handle(
        Request::create('http://vertex.localhost/admin'),
        fn () => response('ok')
    );

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toContain('Vertex Foods cannot be signed in to right now')
        // Why access is off is between SummerHill and the company. Every employee of it
        // sees this page, so the reason cannot be on it.
        ->and($response->getContent())->not->toContain('payment')
        ->and($response->getContent())->not->toContain('suspend');
});

it('lets the address with no company in it through to the welcome page', function () {
    $reached = false;

    (new BindTenantToRequest)->handle(Request::create('http://localhost/'), function () use (&$reached) {
        $reached = true;

        return response('ok');
    });

    expect($reached)->toBeTrue();
});

it('lets background work run for a deactivated client company, because that is a login gate only', function () {
    // The subdomain refuses a deactivated company (see the middleware test above). Work that
    // names the company itself is not blocked: a company locked out for non-payment still needs
    // its records intact, and the reminder and document passes do not arrive through a subdomain.
    $this->vertex->update(['active' => false]);

    expect(TenantContext::run($this->vertex, fn () => WallFixture::query()->count()))->toBe(2);
});

it('reads the right client company\'s rows from a queued job', function () {
    CountFixturesJob::$counted = null;

    dispatch(new CountFixturesJob($this->vertex->getKey()));

    expect(CountFixturesJob::$counted)->toBe(2)
        ->and(TenantContext::id())->toBeNull();
});

it('reads the right client company\'s rows from an artisan command', function () {
    Artisan::command('wall:count {tenant}', function () {
        $this->line((string) TenantContext::run(
            (int) $this->argument('tenant'),
            fn () => WallFixture::query()->count()
        ));
    });

    $this->artisan('wall:count', ['tenant' => $this->meridian->getKey()])
        ->expectsOutput('1')
        ->assertExitCode(0);
});

it('does not leave the application scoped when the database refuses the marker', function () {
    // Break the connection's current transaction, so the next statement on it fails.
    try {
        DB::select('select 1 from a_table_that_was_never_created');
    } catch (QueryException) {
        // Expected — this is the setup, not the assertion.
    }

    expect(fn () => TenantContext::run($this->meridian, fn () => 'never reached'))
        ->toThrow(QueryException::class);

    // The application must not believe it is scoped to a client company the database
    // was never told about. Nothing touches the database after this point, because the
    // transaction is already abandoned.
    expect(TenantContext::id())->toBeNull()
        ->and(TenantContext::isCrossTenant())->toBeFalse();
});

it('logs every reach across client companies', function () {
    Log::spy();

    $counted = TenantContext::cross(
        fn () => WallFixture::query()->count(),
        reason: 'test: the audited cross-company path'
    );

    expect($counted)->toBe(3);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'tenant.cross_access'
            && $context['reason'] === 'test: the audited cross-company path');
});
