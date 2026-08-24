<?php

namespace App\Providers;

use App\Authorization\PermissionResolver;
use App\Authorization\TenantPasswordBrokers;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not a singleton: Laravel discards a scoped instance between requests
        // and between queued jobs, which is exactly how long a permission answer should
        // be remembered. The client company is in every cache key as well, so even a
        // leak across that boundary could not answer one client's question from
        // another's rows.
        $this->app->scoped(PermissionResolver::class);

        // Scoped for the same reason, and the client company is likewise in every cache
        // key: a value read here decides who has to approve a hire, so a stale answer
        // held past the end of a request is not a cheap mistake.
        $this->app->scoped(Settings::class);

        // Laravel's password reset store looks a person up by email address alone, with
        // no client company, so a link issued at one client resets an account at
        // another. `extend` rather than `bind` because the framework registers
        // `auth.password` from a deferred provider that would otherwise load after us
        // and overwrite the binding; an extender is applied after that provider has had
        // its say.
        $this->app->extend(
            'auth.password',
            fn () => new TenantPasswordBrokers($this->app),
        );
    }

    public function boot(): void
    {
        $this->declareTheSwitchesClientsCanChange();

        // No account survives its last working day, and that has to be true of
        // authentication itself rather than only of the panel's own door.
        //
        // All three ways the framework finds an account — signing in, rehydrating a
        // session on the next request, and the remember-me cookie — run through this one
        // query, so the condition is written once. The effect worth knowing: an account
        // deactivated while somebody is signed in stops working on their very next
        // request, which is exactly what an exit on a last working day should do.
        //
        // What a leaver is still owed after that date — the relieving letter, the
        // settlement statement, the Form 16, a disputed settlement line — travels on
        // signed links to a personal address and needs no account at all.
        // Named model rather than every table this driver serves: a last working day is
        // something a client's employee has, and SummerHill's own accounts are not
        // employed by any client. Left unnamed, this asked a table with no such column
        // for it and refused every sign-in to our own area with a database error.
        Auth::provider('eloquent', function (Application $app, array $config): EloquentUserProvider {
            $provider = new EloquentUserProvider($app['hash'], $config['model']);

            return $config['model'] === User::class
                ? $provider->withQuery(fn (Builder $query) => $query->where('active', true))
                : $provider;
        });
    }

    /**
     * The switches a client company can change, assembled from code every time the
     * process starts.
     *
     * Declared here rather than in a list of their own because each belongs to whichever
     * module reads it, and a switch declared for a module that has not arrived is a
     * control a client can change to no effect.
     */
    private function declareTheSwitchesClientsCanChange(): void
    {
        Settings::declare(new SettingDeclaration(
            key: AssigneeResolver::StandInSetting,
            type: 'integer',
            default: null,
            rule: 'nullable|integer|min:1',
            help: 'Who holds a step when nobody holds the role it asked for. Left unset, such a step '
                .'stays open with nobody on it and says so on the case, which is safer than it looks: '
                .'it can never approve or complete itself.',
        ));
    }
}
