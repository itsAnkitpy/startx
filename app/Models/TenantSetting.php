<?php

namespace App\Models;

use App\Exceptions\SettingRefused;
use App\Settings\Settings;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One client company's value for one switch.
 *
 * What a switch is, what kind of value it may hold and whether this value is allowed
 * all live in code ({@see Settings}), not in this table — so the guards below ask the
 * declared list rather than a column.
 *
 * They sit on the model rather than only in the store for the same reason the guard on
 * a role's permission does: it covers every write path, including a direct create in a
 * seeder or a test. A name nothing declares would otherwise be stored as a switch a
 * client believes they have set and no code ever reads.
 *
 * `updated_by` is stamped from whoever is signed in rather than being a field a form
 * may fill, the same rule and the same reason as the field naming who entered a job
 * row: it answers where the change came from, and a submitted field that answers that
 * can lie about it. Null is a real answer and means a seed or a scheduled pass.
 */
#[Fillable(['key', 'value'])]
class TenantSetting extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            // A jsonb column, so a switch holding a number reads back a number and one
            // holding a flag reads back a flag, with no per-kind columns.
            'value' => 'json',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $key = (string) $row->key;

            // Throws where nothing in code declares this name.
            $declaration = Settings::declarationOf($key);
            $failure = $declaration->failureFor($row->value);

            if ($failure !== null) {
                throw SettingRefused::valueRejected($key, $failure);
            }

            $row->updated_by = Auth::id();
        });
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
