<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\FormFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question on one form.
 *
 * The list of types is closed and stays closed. Twelve covers every form the old tool's
 * seven clearance screens actually used, and a fixed list is what the market does —
 * Airtable, the most permissive product in this space, fixes its own at 34 and lets no
 * customer add to it. A thirteenth type is a code change with a reason written down, not
 * a row somebody can type.
 */
#[Fillable([
    'form_definition_id', 'key', 'label', 'type', 'required', 'options',
    'validation', 'sort_order', 'visible_if',
])]
class FormField extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FormFieldFactory> */
    use HasFactory;

    public const Text = 'text';

    public const Textarea = 'textarea';

    public const Number = 'number';

    /**
     * Separate from `number` because module 08 keys off it: any money question on a
     * clearance step can be turned into a line of the settlement statement, and a plain
     * number cannot be, because nothing says whether it is rupees or a count of laptops.
     */
    public const Money = 'money';

    public const Date = 'date';

    public const Select = 'select';

    public const Multiselect = 'multiselect';

    public const Boolean = 'boolean';

    public const File = 'file';

    public const UserPicker = 'user_picker';

    public const OrgUnitPicker = 'org_unit_picker';

    public const DesignationPicker = 'designation_picker';

    public const Types = [
        self::Text, self::Textarea, self::Number, self::Money, self::Date, self::Select,
        self::Multiselect, self::Boolean, self::File, self::UserPicker,
        self::OrgUnitPicker, self::DesignationPicker,
    ];

    /** The two types whose answer has to be one of a list the client wrote. */
    public const TypesWithChoices = [self::Select, self::Multiselect];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
            'validation' => 'array',
            'sort_order' => 'integer',
            'visible_if' => 'array',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class, 'form_definition_id');
    }

    /**
     * The values a `select` or `multiselect` answer may take.
     *
     * Read off the stored `{value, label}` pairs rather than off the labels, so a client
     * renaming a choice on the next version of the form does not change what an answer
     * already given meant.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $option): ?string => is_array($option) && isset($option['value'])
                ? (string) $option['value']
                : null,
            (array) $this->options,
        )));
    }
}
