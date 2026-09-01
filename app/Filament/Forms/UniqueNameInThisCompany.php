<?php

namespace App\Filament\Forms;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Refuses a name another row on the same list already carries, ignoring capitals.
 *
 * Both client-kept lists — designations and offices — are one name per company however
 * it is capitalised, and the database holds that as an index over the lowercased name.
 * Filament's own uniqueness check compares the name exactly as typed, so "senior manager"
 * beside an existing "Senior Manager" passes the form and is then refused by the
 * database, which reaches a client as an error page. This closes the gap between the two.
 *
 * Written once because two screens want it. The client company is not in the query
 * because the model puts it there — every read is already narrowed to the company in
 * scope.
 */
class UniqueNameInThisCompany implements ValidationRule
{
    public function __construct(
        /** @var class-string<Model> */
        private readonly string $model,
        private readonly ?Model $ignoring = null,
        private readonly string $message = 'You already have one with this name.',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $taken = $this->model::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
            ->when($this->ignoring, fn ($query) => $query->whereKeyNot($this->ignoring))
            ->exists();

        if ($taken) {
            $fail($this->message);
        }
    }
}
