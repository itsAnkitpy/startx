<?php

namespace App\Filament\Pages;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\User;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The switches this client company runs on, and the one screen that changes them.
 *
 * **Built from the declared list rather than written out.** Every switch already carries
 * the words it is called by, what kind of value it holds, what it is worth untouched, the
 * rule a new value has to pass and a line of help — so a screen that reads those needs
 * nothing said about any particular switch, and the next module to declare one gets its
 * control here for free. Django's Constance does the same thing from the same starting
 * point, and it is the reason the settings store was built as declarations in the first
 * place.
 *
 * The alternative is a screen with a box per switch, and it fails on the second module:
 * a switch declared in code with no box drawn for it is a control a client believes they
 * have and cannot reach, and nothing fails loudly when it happens.
 *
 * **Casting a form's answer to the shape the switch declares happens here**, which is
 * where the settings store says it should: a browser hands back text for every box, and
 * this is the only part of the product that knows a form was involved.
 *
 * @property-read Schema $form
 */
class CompanySettings extends Page
{
    protected string $view = 'filament.pages.company-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 90;

    /**
     * What is currently in the boxes.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Changing a switch changes what happens on every case raised from now on, which is
     * why it is its own action rather than riding along with editing roles. A company can
     * hand one out without the other.
     */
    public static function canAccess(): bool
    {
        $person = auth()->user();

        return $person instanceof User
            && app(PermissionResolver::class)->allows($person, Permission::ManageSettings);
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill(
            collect(Settings::declared())
                ->map(fn (SettingDeclaration $declaration): mixed => $settings->get($declaration->key))
                ->all(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make(
                    collect(Settings::declared())->map($this->controlFor(...))->values()->all(),
                )
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save settings')->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $settings = app(Settings::class);

        foreach ($this->form->getState() as $key => $typed) {
            $declaration = Settings::declarationOf($key);
            $value = $this->asDeclared($declaration, $typed);

            // Only what actually moved. The page carries every switch, so writing all of
            // them would stamp whoever pressed Save onto switches they never touched — and
            // the whole claim of this product is that a change can be pinned on somebody.
            if ($value === $settings->get($key)) {
                continue;
            }

            $settings->set($key, $value);
        }

        // The store remembers what it has already read for the life of the request, so
        // without this the boxes would fill back up with the figures that were there
        // before the save.
        $settings->forget();

        Notification::make()->success()->title('Settings saved.')->send();
    }

    /**
     * The control for one switch, chosen by the kind it declares.
     *
     * The switch's own rule is handed to the box, so a figure the store would refuse is
     * refused under the box that holds it rather than as an error page. The store still
     * checks it on the way in — that is the guard every write passes through, including a
     * seeder's — and this is what makes the refusal readable when it comes from here.
     */
    private function controlFor(SettingDeclaration $declaration): TextInput|Toggle
    {
        $control = match ($declaration->type) {
            'boolean' => Toggle::make($declaration->key),
            // Whole rupees with the currency in front of them, which is the only thing
            // separating money from a plain whole number on this screen.
            'money' => TextInput::make($declaration->key)->integer()->prefix('₹'),
            'integer' => TextInput::make($declaration->key)->integer(),
            'text' => TextInput::make($declaration->key),
        };

        return $control
            ->label($declaration->label)
            ->helperText($declaration->help)
            ->rules($declaration->rule);
    }

    /**
     * What was typed, as the shape the switch says it holds.
     *
     * An empty box is nothing rather than empty text: a switch whose rule allows nothing
     * at all is being cleared, and one whose rule does not is refused above before this is
     * reached.
     */
    private function asDeclared(SettingDeclaration $declaration, mixed $typed): mixed
    {
        if ($declaration->type === 'boolean') {
            return (bool) $typed;
        }

        if ($typed === null || $typed === '') {
            return null;
        }

        return match ($declaration->type) {
            'integer', 'money' => (int) $typed,
            'text' => (string) $typed,
        };
    }
}
