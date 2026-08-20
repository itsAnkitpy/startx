<?php

namespace App\Exceptions;

use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use RuntimeException;

/**
 * Every refusal the settings store makes, one named constructor each. One class rather
 * than five, because each of these is the same event from a caller's point of view —
 * the store would not accept what it was given — and the message says which.
 */
class SettingRefused extends RuntimeException
{
    /**
     * A key nothing in code has declared. Refused rather than stored, because a stored
     * value nothing reads is a switch a client believes they have set.
     */
    public static function unknownKey(string $key): self
    {
        $declared = Settings::declared() === []
            ? 'nothing is declared yet'
            : 'declared: '.implode(', ', array_keys(Settings::declared()));

        return new self("[{$key}] is not a setting this system has — {$declared}.");
    }

    public static function unknownType(string $key, string $type): self
    {
        $known = implode(', ', SettingDeclaration::Types);

        return new self("[{$key}] declares the kind [{$type}], which is not one of: {$known}.");
    }

    public static function declaredDefaultRejected(string $key, string $why): self
    {
        return new self(
            "[{$key}] declares a default its own rule refuses, so no client could ever save it "
            ."back: {$why}"
        );
    }

    public static function valueRejected(string $key, string $why): self
    {
        return new self("[{$key}] cannot be set to that: {$why}");
    }

    /**
     * The stored value no longer fits the declaration — a release changed the switch's
     * shape and left a value behind. Refused rather than quietly replaced by the
     * declared default, decided with Ankit on 20 August 2026: silently defaulting a
     * money threshold routes a large hire past the director with nobody told, which is
     * the old system's own compliance hole. A release that changes a switch's shape
     * ships a migration rewriting the stored values.
     */
    public static function storedValueRejected(string $key, string $why): self
    {
        return new self(
            "The stored value of [{$key}] no longer fits what the code declares, so it is refused "
            ."rather than replaced by the default: {$why}"
        );
    }
}
