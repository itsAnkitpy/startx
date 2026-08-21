<?php

use App\Authorization\Permission;
use App\Authorization\StarterRoles;
use App\Filament\Platform\Auth\Login as PlatformLogin;
use App\Filament\Platform\Resources\Tenants\Pages\CreateTenant;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| SummerHill's own way in. Two things have to hold, and they are the reasons this area
| exists rather than a button on a client's screen:
|
| It answers on the plain address only, and it reads a different table from the one
| client employees sign in with — so the two doors cannot be confused for each other in
| either direction.
|
| And setting a client company up is the one thing no client administrator can do,
| because the company does not exist yet. That write crosses the database wall: the
| company row is outside it, its roles and its people are inside it, and with no company
| in scope Postgres refuses them. So the happy path is tested end to end — create the
| company here, then sign one of its administrators in at their own address.
*/

function platformUser(): PlatformUser
{
    return PlatformUser::create([
        'name' => 'Ankit Sharma',
        'email' => 'ankit@summerhill.test',
        'password' => 'password',
    ]);
}

/**
 * @param  list<array<string, string>>|null  $administrators
 * @return array<string, mixed>
 */
function newCompanyForm(?array $administrators = null): array
{
    return [
        'name' => 'Meridian Logistics',
        'slug' => 'meridian',
        'administrators' => $administrators ?? [
            ['first_name' => 'Anjali', 'last_name' => 'Verma', 'work_email' => 'anjali@example.test', 'password' => 'password'],
            ['first_name' => 'Chandni', 'last_name' => 'Rao', 'work_email' => 'chandni@example.test', 'password' => 'password'],
        ],
    ];
}

it('answers on the plain address and nowhere else', function () {
    // The company has to exist, or the wrong-address page answers first and this passes
    // without the panel ever having been asked.
    Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    // A client's address carries their company in scope and everything there belongs to
    // them. Our area belongs to no client, so it does not exist at their address.
    $this->get('http://localhost/platform/login')->assertOk();
    $this->get('http://meridian.localhost/platform/login')->assertNotFound();
});

it('refuses a client employee at our door, because it reads a different table', function () {
    $meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);

    TenantContext::run($meridian, function () {
        User::factory()->create(['work_email' => 'anjali@example.test']);
    });

    Filament::setCurrentPanel('platform');

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => 'anjali@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(auth()->guard('platform')->check())->toBeFalse();
});

it('signs one of our own people in', function () {
    $ankit = platformUser();

    Filament::setCurrentPanel('platform');

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => 'ankit@summerhill.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->guard('platform')->id())->toBe($ankit->getKey());

    // And that account is ours alone: it is no use at a client's own door.
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('sets a client company up with its starting roles and its first administrators', function () {
    Filament::setCurrentPanel('platform');

    Livewire::actingAs(platformUser(), 'platform')
        ->test(CreateTenant::class)
        ->fillForm(newCompanyForm())
        ->call('create')
        ->assertHasNoFormErrors();

    $meridian = Tenant::query()->where('slug', 'meridian')->sole();

    expect($meridian->name)->toBe('Meridian Logistics')
        ->and($meridian->active)->toBeTrue()
        ->and($meridian->onboarded_at)->not->toBeNull();

    TenantContext::run($meridian, function () {
        expect(Role::query()->count())->toBe(count(StarterRoles::definitions()))
            ->and(User::query()->count())->toBe(2);

        $administrator = Role::query()->where('key', Role::AdministratorKey)->sole();

        // Not "they hold a role called Administrator" — that the role actually grants,
        // which is the only question any screen asks.
        expect($administrator->assignments()->count())->toBe(2)
            ->and($administrator->permissions()->count())->toBe(count(Permission::all()));

        // Over the whole company, because these two have to build the structure that
        // any narrower grant would be pointing at.
        expect($administrator->assignments()->whereNull('org_unit_id')->count())->toBe(2);
    });
});

it('leaves the two administrators able to sign in at their own address', function () {
    Filament::setCurrentPanel('platform');

    Livewire::actingAs(platformUser(), 'platform')
        ->test(CreateTenant::class)
        ->fillForm(newCompanyForm())
        ->call('create');

    $meridian = Tenant::query()->where('slug', 'meridian')->sole();

    TenantContext::run($meridian, function () {
        expect(auth()->guard('web')->attempt([
            'work_email' => 'anjali@example.test',
            'password' => 'password',
        ]))->toBeTrue();
    });
});

it('refuses one administrator, because a company keeps two', function () {
    Filament::setCurrentPanel('platform');

    Livewire::actingAs(platformUser(), 'platform')
        ->test(CreateTenant::class)
        ->fillForm(newCompanyForm([
            ['first_name' => 'Anjali', 'last_name' => 'Verma', 'work_email' => 'anjali@example.test', 'password' => 'password'],
        ]))
        ->call('create')
        ->assertHasFormErrors();

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses the same address twice, before the company row is written', function () {
    Filament::setCurrentPanel('platform');

    // The database would catch this on the second insert, by which time the company
    // exists and the first administrator with it.
    Livewire::actingAs(platformUser(), 'platform')
        ->test(CreateTenant::class)
        ->fillForm(newCompanyForm([
            ['first_name' => 'Anjali', 'last_name' => 'Verma', 'work_email' => 'anjali@example.test', 'password' => 'password'],
            ['first_name' => 'Chandni', 'last_name' => 'Rao', 'work_email' => 'anjali@example.test', 'password' => 'password'],
        ]))
        ->call('create')
        ->assertHasFormErrors();

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses an address that is not the shape of one', function () {
    Filament::setCurrentPanel('platform');
    $ankit = platformUser();

    foreach (['Meridian', 'meridian.logistics', '-meridian', 'meri dian'] as $slug) {
        Livewire::actingAs($ankit, 'platform')
            ->test(CreateTenant::class)
            ->fillForm(['name' => 'Meridian Logistics', 'slug' => $slug])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    expect(Tenant::query()->count())->toBe(0);
});

it('makes one of our own accounts from the command line, never from a seeder', function () {
    // A seeder would put a real address and password in the repository and recreate them
    // on every fresh database.
    $this->artisan('startx:platform-user', [
        '--name' => 'Ankit Sharma',
        '--email' => 'ankit@summerhill.test',
    ])->expectsQuestion('Password', 'password')->assertSuccessful();

    expect(PlatformUser::query()->count())->toBe(1);

    Filament::setCurrentPanel('platform');

    Livewire::test(PlatformLogin::class)
        ->fillForm(['email' => 'ankit@summerhill.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
});
