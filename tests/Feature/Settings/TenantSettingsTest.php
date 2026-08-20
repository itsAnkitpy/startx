<?php

use App\Exceptions\SettingRefused;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;
use Illuminate\Support\Facades\DB;

/*
| The switches a client company can change. Storage, the list of switches declared in
| code, and validation — and no switches declared, because every module that wants one
| declares its own when it arrives with code that reads it.
|
| So the switch below is this test's own, which is the point of the design: the list is
| declarations rather than cases of an enum precisely so that a test can add one. Until
| module 03 declares the first real switch, this is the only thing standing behind the
| code.
*/

/**
 * A stand-in for the sort of switch a module will really declare: the salary above
 * which a hiring request needs the director (module 05's, when it arrives).
 */
function declareApprovalThreshold(int $default = 1500000): SettingDeclaration
{
    $declaration = new SettingDeclaration(
        key: 'test_approval_threshold',
        type: 'integer',
        default: $default,
        rule: 'integer|min:0',
        help: 'Hires above this annual figure need the director.',
    );

    Settings::declare($declaration);

    return $declaration;
}

beforeEach(function () {
    Settings::forgetDeclared();

    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->vertex = Tenant::factory()->create(['name' => 'Vertex Foods', 'slug' => 'vertex']);
    $this->settings = app(Settings::class);
});

afterEach(function () {
    Settings::forgetDeclared();
});

it('reads back the declared default where the client has never set a value', function () {
    declareApprovalThreshold(1500000);

    TenantContext::run($this->meridian, function () {
        expect($this->settings->get('test_approval_threshold'))->toBe(1500000)
            ->and(TenantSetting::query()->count())->toBe(0);
    });
});

it('refuses a name nothing in code declares, rather than storing it', function () {
    TenantContext::run($this->meridian, function () {
        expect(fn () => $this->settings->set('invented_switch', 5))
            ->toThrow(SettingRefused::class, '[invented_switch] is not a setting this system has');

        expect(fn () => $this->settings->get('invented_switch'))
            ->toThrow(SettingRefused::class);

        expect(DB::table('tenant_settings')->count())->toBe(0);
    });
});

it('refuses a value the declared rule rejects, and nothing reaches the table', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        expect(fn () => $this->settings->set('test_approval_threshold', 'not a number'))
            ->toThrow(SettingRefused::class, 'cannot be set to that');

        expect(fn () => $this->settings->set('test_approval_threshold', -1))
            ->toThrow(SettingRefused::class);

        expect(DB::table('tenant_settings')->count())->toBe(0)
            // And the read still hands back the default rather than the refused value.
            ->and($this->settings->get('test_approval_threshold'))->toBe(1500000);
    });
});

it('refuses an undeclared switch and a bad value written straight to the table', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        // A seeder or an import writing the row itself, going nowhere near the store.
        expect(fn () => TenantSetting::create(['key' => 'invented_switch', 'value' => 5]))
            ->toThrow(SettingRefused::class, 'is not a setting this system has');

        expect(fn () => TenantSetting::create(['key' => 'test_approval_threshold', 'value' => 'lots']))
            ->toThrow(SettingRefused::class, 'cannot be set to that');

        expect(DB::table('tenant_settings')->count())->toBe(0);
    });
});

it('keeps one client company value invisible to another, including through a raw query', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        $this->settings->set('test_approval_threshold', 2500000);
    });

    TenantContext::run($this->vertex, function () {
        expect($this->settings->get('test_approval_threshold'))->toBe(1500000)
            ->and(TenantSetting::query()->count())->toBe(0)
            // The database refuses it too, not only the Eloquent scope.
            ->and(DB::table('tenant_settings')->count())->toBe(0);
    });

    TenantContext::run($this->meridian, function () {
        expect($this->settings->get('test_approval_threshold'))->toBe(2500000);
    });
});

it('stamps who changed a value from whoever is signed in, and cannot be told otherwise', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        $anjali = User::factory()->named('Anjali Rao')->create();
        $priya = User::factory()->named('Priya Nair')->create();

        $this->actingAs($anjali);

        $row = $this->settings->set('test_approval_threshold', 1800000);

        expect($row->updated_by)->toBe($anjali->getKey());

        // Naming somebody else changes nothing, and it is set directly here rather than
        // through fill() on purpose: fill() is refused by the fillable list, so a test
        // written that way would pass even if the stamp were dropped altogether.
        $row->updated_by = $priya->getKey();
        $row->save();

        expect($row->fresh()->updated_by)->toBe($anjali->getKey());

        // The other half of the same rule: a submitted field cannot set it either.
        Settings::declare(new SettingDeclaration(
            key: 'test_second_switch',
            type: 'boolean',
            default: false,
            rule: 'boolean',
            help: 'A second switch, so this write is not the same row again.',
        ));

        $submitted = TenantSetting::create([
            'key' => 'test_second_switch',
            'value' => true,
            'updated_by' => $priya->getKey(),
        ]);

        expect($submitted->updated_by)->toBe($anjali->getKey());
    });
});

it('leaves who changed it empty where nobody is signed in, which is a seed or a scheduled pass', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        $row = $this->settings->set('test_approval_threshold', 1800000);

        expect($row->updated_by)->toBeNull();
    });
});

it('refuses a stored value that no longer fits a changed declaration, rather than defaulting it', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        $this->settings->set('test_approval_threshold', 900000);
    });

    // A release narrows the switch: the figure now has to be at least a million. The
    // stored 900000 no longer fits, and quietly handing back the default instead would
    // route a large hire past the director with nobody told.
    Settings::declare(new SettingDeclaration(
        key: 'test_approval_threshold',
        type: 'integer',
        default: 1500000,
        rule: 'integer|min:1000000',
        help: 'Hires above this annual figure need the director.',
    ));

    TenantContext::run($this->meridian, function () {
        $this->settings->forget();

        expect(fn () => $this->settings->get('test_approval_threshold'))
            ->toThrow(SettingRefused::class, 'no longer fits what the code declares');
    });
});

it('lets a switch hold nothing where its own rule allows it', function () {
    Settings::declare(new SettingDeclaration(
        key: 'test_stand_in',
        type: 'integer',
        default: null,
        rule: 'nullable|integer',
        help: 'Who covers a vacant role. Nobody is a real answer.',
    ));

    TenantContext::run($this->meridian, function () {
        expect($this->settings->get('test_stand_in'))->toBeNull();

        $this->settings->set('test_stand_in', 4);
        expect($this->settings->get('test_stand_in'))->toBe(4);

        // Cleared again, which is a different answer from never having set it and has
        // to survive the round trip rather than raising a database error.
        $this->settings->set('test_stand_in', null);

        expect($this->settings->get('test_stand_in'))->toBeNull()
            ->and(TenantSetting::query()->where('key', 'test_stand_in')->exists())->toBeTrue();
    });
});

it('refuses to read or write with no client company in scope', function () {
    declareApprovalThreshold();

    expect(fn () => $this->settings->get('test_approval_threshold'))
        ->toThrow(TenantContextMissing::class, 'cannot be read or written');

    expect(fn () => $this->settings->set('test_approval_threshold', 1800000))
        ->toThrow(TenantContextMissing::class);
});

it('refuses a declaration whose own default its rule would reject', function () {
    expect(fn () => new SettingDeclaration(
        key: 'test_impossible',
        type: 'integer',
        default: -5,
        rule: 'integer|min:0',
        help: 'Nobody could ever save this back.',
    ))->toThrow(SettingRefused::class, 'declares a default its own rule refuses');
});

it('refuses a declaration whose kind is not one the system knows', function () {
    expect(fn () => new SettingDeclaration(
        key: 'test_unknown_kind',
        type: 'colour',
        default: 'blue',
        rule: 'string',
        help: 'There is no colour control to render.',
    ))->toThrow(SettingRefused::class, 'which is not one of');
});

it('reads the table once per client company however many times it is asked', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        $this->settings->set('test_approval_threshold', 2500000);
    });

    $this->settings->forget();
    $reads = 0;

    DB::listen(function ($query) use (&$reads) {
        if (str_contains($query->sql, 'tenant_settings')) {
            $reads++;
        }
    });

    TenantContext::run($this->meridian, function () {
        $this->settings->get('test_approval_threshold');
        $this->settings->get('test_approval_threshold');
    });

    expect($reads)->toBe(1);

    // The client company is in the key, so the next company's question is not answered
    // from this one's row — which is the failure that would otherwise be silent.
    TenantContext::run($this->vertex, function () {
        expect($this->settings->get('test_approval_threshold'))->toBe(1500000);
    });

    expect($reads)->toBe(2);
});

it('answers the client company in scope even on the audited cross-client path', function () {
    declareApprovalThreshold();

    TenantContext::run($this->meridian, function () {
        $this->settings->set('test_approval_threshold', 2500000);
    });

    // The path SummerHill's own staff use to look at every client at once. It drops the
    // narrowing that normally answers "which company", and this table is keyed by the
    // switch's name alone — so without the company named in the lookup, Vertex's question
    // was answered from Meridian's row and Vertex's save wrote over it.
    TenantContext::run($this->vertex, function () {
        TenantContext::cross(function () {
            expect($this->settings->get('test_approval_threshold'))->toBe(1500000);

            $this->settings->set('test_approval_threshold', 400000);
        });
    });

    TenantContext::run($this->meridian, function () {
        $this->settings->forget();
        expect($this->settings->get('test_approval_threshold'))->toBe(2500000);
    });

    TenantContext::run($this->vertex, function () {
        $this->settings->forget();
        expect($this->settings->get('test_approval_threshold'))->toBe(400000);
    });
});

it('holds a switch to the kind of value it says it holds', function () {
    declareApprovalThreshold();

    Settings::declare(new SettingDeclaration(
        key: 'test_second_switch',
        type: 'boolean',
        default: false,
        rule: 'boolean',
        help: 'A yes-or-no switch.',
    ));

    TenantContext::run($this->meridian, function () {
        // Laravel's own rules would let both of these through: `integer` accepts the text
        // "2500000" and `boolean` accepts "1". Stored, they read back as text, and the
        // screen that renders a control from the declared kind and the process that
        // refuses a numeric comparison against a text switch would both be reading a lie.
        expect(fn () => $this->settings->set('test_approval_threshold', '2500000'))
            ->toThrow(SettingRefused::class, 'must be a whole number, and this is string');

        expect(fn () => $this->settings->set('test_second_switch', '1'))
            ->toThrow(SettingRefused::class, 'must be true or false, and this is string');

        expect(DB::table('tenant_settings')->count())->toBe(0);

        // The declared shape itself still saves and reads back unchanged.
        $this->settings->set('test_approval_threshold', 2500000);
        $this->settings->set('test_second_switch', true);

        expect($this->settings->get('test_approval_threshold'))->toBe(2500000)
            ->and($this->settings->get('test_second_switch'))->toBeTrue();
    });
});

it('refuses a declaration whose kind disagrees with its own rule', function () {
    expect(fn () => new SettingDeclaration(
        key: 'test_mislabelled',
        type: 'integer',
        default: 'saturday',
        rule: 'string',
        help: 'Says it holds a number and holds a word.',
    ))->toThrow(SettingRefused::class, 'must be a whole number, and this is string');
});
