<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * One of SummerHill's own people. Holds nothing but a sign-in: what this account can
 * do is decided by which area it can reach, not by rows granting it powers, because
 * there is one kind of platform account and everybody who has one does the same job.
 *
 * There is no client company on it and no row-level security over it — see the
 * migration for why.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class PlatformUser extends Authenticatable implements FilamentUser
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Only our own area, never a client's. Reaching a client's panel is already
     * impossible — it reads a different table through a different guard — so this is
     * the belt for the day somebody points a third panel at this guard.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'platform';
    }
}
