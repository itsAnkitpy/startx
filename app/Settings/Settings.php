<?php

namespace App\Settings;

use App\Exceptions\SettingRefused;
use App\Models\TenantSetting;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;

/**
 * Every switch a client company can change, and the one place a value is read or
 * written.
 *
 * Two halves with two lifetimes, which is why they sit in one class without fighting.
 * The declared switches are code, so they are static and last as long as the process.
 * The values read from the database belong to one client and one moment, so they are
 * remembered on the instance — and this class is bound as a scoped instance, which
 * Laravel discards between requests and between queued jobs.
 *
 * The client company is in the cache key rather than the cache being cleared when the
 * company in scope changes. Clearing on switch works only while every future entry
 * point remembers to do it, and the failure is silent and crosses clients. With the
 * company in the key, forgetting is impossible: another company's read finds no entry
 * and goes to the database. Module 06's scheduled pass loops over client companies
 * inside one process, so this is not hypothetical.
 *
 * Nothing is declared here. Each module declares its own switch when it arrives with
 * code that reads one — decided with Ankit on 20 August 2026, because six switches
 * declared for unbuilt modules would be a screen a client could change to no effect.
 */
class Settings
{
    /** @var array<string, SettingDeclaration> */
    private static array $declared = [];

    /** @var array<string, mixed> Keyed by client company and switch name. */
    private array $values = [];

    /**
     * Declare a switch. Called from a service provider's boot by whichever module owns
     * it, so the list is assembled from code every time the process starts.
     */
    public static function declare(SettingDeclaration $declaration): void
    {
        self::$declared[$declaration->key] = $declaration;
    }

    /** @return array<string, SettingDeclaration> */
    public static function declared(): array
    {
        return self::$declared;
    }

    public static function isDeclared(string $key): bool
    {
        return isset(self::$declared[$key]);
    }

    public static function declarationOf(string $key): SettingDeclaration
    {
        return self::$declared[$key] ?? throw SettingRefused::unknownKey($key);
    }

    /**
     * Empty the declared list. For tests, which declare a switch of their own and must
     * not leave it behind for the next test.
     */
    public static function forgetDeclared(): void
    {
        self::$declared = [];
    }

    /**
     * This client's value for a switch, or the declared default where they have never
     * set one.
     */
    public function get(string $key): mixed
    {
        $declaration = self::declarationOf($key);
        $tenantId = TenantContext::id() ?? throw TenantContextMissing::forSetting($key);
        $cacheKey = $tenantId.'|'.$key;

        if (array_key_exists($cacheKey, $this->values)) {
            return $this->values[$cacheKey];
        }

        $row = self::rowFor($tenantId, $key);

        if ($row === null) {
            return $this->values[$cacheKey] = $declaration->default;
        }

        $failure = $declaration->failureFor($row->value);

        if ($failure !== null) {
            throw SettingRefused::storedValueRejected($key, $failure);
        }

        return $this->values[$cacheKey] = $row->value;
    }

    /**
     * Set this client's value for a switch. The name and the value are checked by the
     * model on the way out, so a value the code cannot use never reaches the table
     * whether it arrives through here or through a seeder.
     */
    public function set(string $key, mixed $value): TenantSetting
    {
        $tenantId = TenantContext::id() ?? throw TenantContextMissing::forSetting($key);

        $row = self::rowFor($tenantId, $key);

        if ($row === null) {
            $row = TenantSetting::create(['key' => $key, 'value' => $value]);
        } else {
            $row->value = $value;
            $row->save();
        }

        $this->forget();

        return $row;
    }

    /**
     * This client company's row for a switch, naming the company rather than leaning on
     * the scope that is normally applied for us.
     *
     * The company is named because the audited cross-client path deliberately drops that
     * scope, and this table is keyed by the switch's name alone. Without the company here,
     * a read on that path answered from whichever company's row came first, and a write
     * saved over that company's value while a different one was in scope — both proven
     * against this database on 20 August 2026. The permission resolver needs no such line
     * because it looks a person up, and a person belongs to exactly one company.
     */
    private static function rowFor(int $tenantId, string $key): ?TenantSetting
    {
        return TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();
    }

    /**
     * Forget every value read so far. Needed where a value is written and the same
     * request then reads it back — a test, or the screen that saved it.
     */
    public function forget(): void
    {
        $this->values = [];
    }
}
