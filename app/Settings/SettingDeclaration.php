<?php

namespace App\Settings;

use App\Exceptions\SettingRefused;
use Illuminate\Support\Facades\Validator;

/**
 * One switch a client company can change: its permanent name, what kind of value it
 * holds, what it is worth when the client has never touched it, the rule a submitted
 * value has to pass, and one line of help for whoever is looking at the screen.
 *
 * Declarations rather than cases of an enum, decided with Ankit on 20 August 2026 for
 * one reason: the list ships with no switches at all, and a test cannot add a case to
 * an enum. A test declaring a switch of its own is the only thing standing behind this
 * code until module 03 declares the first real one.
 *
 * @see Settings for the list itself and for reading a value
 */
final readonly class SettingDeclaration
{
    /**
     * What kinds of value a switch may hold, as a fixed list because the kind has two
     * readers coming: module 12 renders a control from it, and module 02 refuses at
     * publish time a numeric comparison written against a text switch. A money kind
     * joins this list with the first switch that holds money — module 05's hiring
     * threshold — for the same reason module 04 keeps money apart from a plain number.
     */
    public const Types = ['boolean', 'integer', 'text'];

    public function __construct(
        public string $key,
        public string $type,
        public mixed $default,
        public string $rule,
        public string $help,
    ) {
        if (! in_array($this->type, self::Types, true)) {
            throw SettingRefused::unknownType($this->key, $this->type);
        }

        // A default its own rule would refuse is a switch nobody can use: every read
        // hands out a value the client cannot save back. Caught here, when the switch
        // is declared, rather than on somebody's first read of it.
        $failure = $this->failureFor($this->default);

        if ($failure !== null) {
            throw SettingRefused::declaredDefaultRejected($this->key, $failure);
        }
    }

    /**
     * Why this value is not allowed for this switch, or null if it is allowed.
     */
    public function failureFor(mixed $value): ?string
    {
        $validator = Validator::make(['value' => $value], ['value' => $this->rule]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first('value');
        }

        return $this->shapeFailureFor($value);
    }

    /**
     * Why this value is not the shape this switch says it holds.
     *
     * The rule alone does not settle this, checked on 20 August 2026: Laravel's `integer`
     * rule accepts the text "2500000" as well as the number, and its `boolean` rule accepts
     * "1" and "0", so a switch declared as a whole number could store text and read text
     * back. Two readers coming rely on the declared kind meaning what it says — module 12
     * renders a control from it, and module 02 refuses at publish time a numeric comparison
     * written against a text switch — so the kind is checked here rather than trusted.
     *
     * Whatever a screen collects has to arrive as the declared shape. An HTML form hands
     * back text for every field, so the configuration screen casts on the way in; that is
     * the right place for it, because it is the only part that knows a form was involved.
     */
    private function shapeFailureFor(mixed $value): ?string
    {
        // The rule above has already had its say on nothing-at-all: one that does not
        // allow it refused it there, and one that does allow it means nothing is a real
        // answer. Either way there is no shape left to check.
        if ($value === null) {
            return null;
        }

        $found = get_debug_type($value);

        return match ($this->type) {
            'boolean' => is_bool($value) ? null : "must be true or false, and this is {$found}.",
            'integer' => is_int($value) ? null : "must be a whole number, and this is {$found}.",
            'text' => is_string($value) ? null : "must be text, and this is {$found}.",
        };
    }
}
