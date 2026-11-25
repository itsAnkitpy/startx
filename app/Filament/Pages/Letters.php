<?php

namespace App\Filament\Pages;

use App\Authorization\Permission;
use App\Authorization\PermissionResolver;
use App\Models\LetterSetup;
use App\Models\User;
use App\Process\Letter;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Where a client company writes the letters a leaver is given, and uploads what they are
 * printed with: the letterhead, a footer strip and the signature.
 *
 * **The editor is Filament's, offering only what reaches the PDF.** Bold, italic, underline,
 * two heading sizes, lists and the tokens. No alignment, link, colour or picture, which the
 * letter does not print, and no table, which it prints but a letter does not need. Pasted
 * text keeps whatever it carried, so saving drops what the letter would and fills the
 * editors again with what is kept.
 *
 * **The preview draws what is on the screen, saved or not**, with made-up details for the
 * leaver, so trying a sentence never changes a letter anybody is given.
 *
 * @property-read Schema $form
 */
class Letters extends Page
{
    /** In pixels. A letterhead at print quality across A4 is about 2,500 wide. */
    private const WidestPicture = 3000;

    private const TallestPicture = 1500;

    protected string $view = 'filament.pages.letters';

    protected static string|UnitEnum|null $navigationGroup = 'Company setup';

    protected static ?string $navigationLabel = 'Letters';

    protected static ?string $title = 'Letters';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    /** After the messages at 70, before the settings at 90. */
    protected static ?int $navigationSort = 80;

    /**
     * What is currently in the boxes.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Over the whole company, as rewording the emails is: the same letter goes to a leaver in
     * every branch.
     */
    public static function canAccess(): bool
    {
        $person = auth()->user();

        return $person instanceof User
            && app(PermissionResolver::class)->allowsEverywhere($person, Permission::WriteLetters);
    }

    public function mount(): void
    {
        $this->fillFromSaved();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            // The saved row, so an upload box refuses a path the browser sends back that the
            // row does not already hold.
            ->model(LetterSetup::current())
            ->components([
                Form::make([
                    Section::make('Letterhead, signature and footer')
                        ->description('Printed on both letters. Each picture is a PNG or JPEG of at most 2 MB.')
                        ->columns(2)
                        ->schema([
                            $this->picture('letterhead', 'Letterhead')
                                ->helperText('Across the top of the first page, the full width of the letter.')
                                ->columnSpanFull(),
                            $this->picture('footer', 'Footer strip')
                                ->helperText('Along the bottom of every page, for your registered office or contact details. Leave it empty for none.')
                                ->columnSpanFull(),
                            $this->picture('signature', 'Signature')
                                ->helperText('A scan of the signature, with your company stamp in the same picture if you stamp letters. It is printed under the last line of the letter.')
                                ->columnSpanFull(),
                            TextInput::make('signed_by')
                                ->label('Signed by')
                                ->helperText('Printed under the signature.')
                                ->maxLength(255),
                            TextInput::make('signed_as')
                                ->label('Their designation')
                                ->maxLength(255),
                        ]),
                    ...array_map($this->letter(...), array_keys(Letter::Kinds), Letter::Kinds),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save letters')->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $letter = app(Letter::class);

        foreach (array_keys(Letter::Kinds) as $kind) {
            $state[$kind] = $letter->asPrinted($state[$kind]);
        }

        LetterSetup::current()->fill($state)->save();

        // So a centring or a picture the letter dropped leaves the screen too.
        $this->fillFromSaved();

        Notification::make()->success()->title('Letters saved.')->send();
    }

    private function fillFromSaved(): void
    {
        $setup = LetterSetup::current();

        $this->form->fill([
            ...$setup->only(['letterhead', 'footer', 'signature', 'signed_by', 'signed_as']),
            ...collect(Letter::Kinds)->map(fn (string $name, string $kind): string => $setup->wordingOf($kind))->all(),
        ]);
    }

    private function letter(string $kind, string $name): Section
    {
        return Section::make($name)
            ->key($kind.'_section')
            ->headerActions([$this->preview($kind, $name)])
            ->schema([
                RichEditor::make($kind)
                    ->label($name)
                    ->hiddenLabel()
                    // The letter's text, rather than Filament's `required`, which passes an
                    // editor left holding only blank lines. A blank line pasted from Word
                    // holds a non-breaking space, which `trim` keeps.
                    ->rule(fn (RichEditor $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                        if (preg_match('/^[\s\p{Z}]*$/u', $component->getTipTapEditor()->setContent($value)->getText()) === 1) {
                            $fail('A letter needs some wording.');
                        }
                    })
                    // Characters of text. A real letter is about 2,000, and this message comes
                    // long before the letter's own limit refuses one as an error.
                    ->maxLength(20000)
                    ->mergeTags(Letter::Tokens)
                    ->activePanel('mergeTags')
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline'],
                        ['h2', 'h3'],
                        ['bulletList', 'orderedList'],
                        ['mergeTags'],
                        ['undo', 'redo'],
                    ])
                    ->floatingToolbars([])
                    ->fileAttachments(false),
            ]);
    }

    /** A PDF of the letter as it stands on the screen, filled with made-up details. */
    private function preview(string $kind, string $name): Action
    {
        return Action::make('preview'.Str::studly($kind))
            ->label('Preview as PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(function () use ($kind, $name): StreamedResponse {
                $this->form->validate();

                /** @var User $person */
                $person = auth()->user();
                $setup = LetterSetup::current();
                $letter = app(Letter::class);

                $pdf = $letter->pdf($letter->page($letter->sampleTokens($person->tenant), $this->data[$kind], [
                    'letterhead' => $this->pictureInTheBox('letterhead', $setup),
                    'footer' => $this->pictureInTheBox('footer', $setup),
                    'signature' => $this->pictureInTheBox('signature', $setup),
                    'signed_by' => (string) ($this->data['signed_by'] ?? ''),
                    'signed_as' => (string) ($this->data['signed_as'] ?? ''),
                ]));

                return response()->streamDownload(
                    function () use ($pdf): void {
                        echo $pdf;
                    },
                    Str::slug($name).'-preview.pdf',
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    /**
     * A picture just chosen, or the one already saved. A saved one is read from the saved row,
     * never from a path the browser sent back.
     */
    private function pictureInTheBox(string $which, LetterSetup $setup): ?string
    {
        $chosen = Arr::first(Arr::wrap($this->data[$which] ?? null));

        return match (true) {
            $chosen instanceof TemporaryUploadedFile => (string) $chosen->get(),
            filled($chosen) => $setup->picture($which),
            default => null,
        };
    }

    private function picture(string $name, string $label): FileUpload
    {
        /** @var User $person */
        $person = auth()->user();

        return FileUpload::make($name)
            ->label($label)
            ->disk((string) config('startx.documents_disk'))
            ->directory('letter-pictures/'.$person->tenant_id)
            ->visibility('private')
            ->acceptedFileTypes(['image/png', 'image/jpeg'])
            ->maxSize(2048)
            ->validationMessages([
                'mimetypes' => 'This needs to be a PNG or JPEG picture.',
                'max' => 'This picture is larger than 2 MB.',
                'tampered' => 'This picture is not one saved for your company. Choose the file again.',
            ])
            ->preventFilePathTampering()
            // Handed over by a closure, which Filament evaluates for the rule itself.
            ->rule(fn (): Closure => self::printsOnALetter(...));
    }

    /**
     * Read from the bytes, not the name: a PNG or JPEG small enough to open, that opens.
     *
     * The size is read from the header before the picture is opened, so a small file claiming
     * to be enormous is refused rather than filling the server's memory. Opening it is what
     * catches a picture with a good header and a damaged body, which would print as an empty
     * box on every letter.
     */
    private static function printsOnALetter(string $attribute, mixed $file, Closure $fail): void
    {
        $bytes = $file instanceof TemporaryUploadedFile ? (string) $file->get() : '';
        $size = @getimagesizefromstring($bytes);

        if ($size === false || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            $fail('This needs to be a PNG or JPEG picture.');

            return;
        }

        if ($size[0] > self::WidestPicture || $size[1] > self::TallestPicture) {
            $fail('This picture is too large. It can be at most '.number_format(self::WidestPicture)
                .' pixels wide and '.number_format(self::TallestPicture).' pixels high.');

            return;
        }

        if (@imagecreatefromstring($bytes) === false) {
            $fail('This picture is damaged and would print as an empty box. Save it again from the original and upload that.');
        }
    }
}
