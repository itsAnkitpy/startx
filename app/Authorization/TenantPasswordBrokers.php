<?php

namespace App\Authorization;

use App\Providers\AppServiceProvider;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use InvalidArgumentException;

/**
 * Hands out password brokers that store reset tokens against a client company.
 *
 * This exists as a subclass because Laravel 13 offers no hook here: unlike guards and
 * user providers, the broker manager has no `extend()` method, and the method that
 * builds the token store is protected. Confirmed against the framework's source on
 * 20 August 2026. So the whole of the change is the one method below, plus a rebind in
 * {@see AppServiceProvider}.
 *
 * The cache-backed store the framework also offers is deliberately not carried over.
 * Nothing configures it, and a reset token belongs in a table where it can be walled
 * off per client company like every other row.
 */
class TenantPasswordBrokers extends PasswordBrokerManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTokenRepository(array $config): TokenRepositoryInterface
    {
        // Refusing loudly rather than quietly handing back the table-backed store. The
        // framework's cache-backed store has no client company on it at all, so
        // configuring it would reopen the hole this class exists to close, and it would
        // do so with no error anywhere.
        if (($config['driver'] ?? null) === 'cache') {
            throw new InvalidArgumentException(
                'The cache-backed password reset store carries no client company, so a reset link '
                .'issued at one client would be accepted at another. Use the table-backed store.'
            );
        }

        $key = (string) $this->app['config']['app.key'];

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        return new TenantPasswordTokens(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            $key,
            ((int) ($config['expire'] ?? 60)) * 60,
            (int) ($config['throttle'] ?? 0),
        );
    }
}
