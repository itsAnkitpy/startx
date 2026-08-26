<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Tenancy\BelongsToTenant;
use Database\Factories\FormDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One version of one client's form — the questions a step asks.
 *
 * A version is a row, not a number on a row, for the same reason a process version is:
 * a step points at the row, the row cannot change once it is live, and so a closed step
 * still asks exactly what it asked. Nothing is copied onto a case anywhere.
 *
 * `key` stays the same across versions; `name` is the client's own words and is theirs
 * to rename whenever they like.
 */
/*
 * `version` and `status` are not fillable on purpose, the same as on a process version.
 * The version comes from counting what exists and the status changes only by going live
 * or being retired, which are acts with checks behind them rather than values on a form.
 */
#[Fillable(['key', 'name'])]
class FormDefinition extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FormDefinitionFactory> */
    use HasFactory;

    /**
     * A draft can be edited and cannot be used by a live process. A published form can be
     * used and cannot be edited. An archived one can do neither and stays readable,
     * because closed cases are still read against it.
     */
    public const Draft = 'draft';

    public const Published = 'published';

    public const Archived = 'archived';

    public const Statuses = [self::Draft, self::Published, self::Archived];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order');
    }

    /**
     * Make this version live and retire the one it replaces.
     *
     * The freeze is not applied here — the database refuses a change to a live form's
     * questions, because the protection a closed step has is that its questions cannot
     * change, and a rule only this method applies is one an import walks round.
     *
     * The order inside the transaction matters: one form has one live version, enforced
     * by the database, so the previous one is retired before this one goes live.
     */
    public function publish(): void
    {
        if ($this->status !== self::Draft) {
            throw ProcessRefused::onlyADraftFormGoesLive($this->name, $this->version, $this->status);
        }

        if ($this->fields()->count() === 0) {
            throw ProcessRefused::aFormWithNoQuestionsCannotGoLive($this->name, $this->version);
        }

        // A list to choose from with nothing on it is a question with no answer
        // available. Required, it leaves the step waiting for ever and the exit open;
        // optional, it is a box nobody can put anything in.
        $withNothingToChoose = $this->fields()
            ->whereIn('type', FormField::TypesWithChoices)
            ->get()
            ->filter(fn (FormField $field): bool => $field->choices() === [])
            ->pluck('label')
            ->all();

        if ($withNothingToChoose !== []) {
            throw ProcessRefused::aQuestionWithNoChoicesCannotGoLive($this->name, $withNothingToChoose);
        }

        DB::transaction(function (): void {
            static::query()
                ->where('key', $this->key)
                ->where('status', self::Published)
                ->whereKeyNot($this->getKey())
                ->update(['status' => self::Archived]);

            $this->status = self::Published;
            $this->save();
        });
    }
}
