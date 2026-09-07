<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Models\NotificationTemplate;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Two boxes, because there are exactly two things a client may change.
 *
 * What the message is called, who reads it and what sends it are on the page above this
 * rather than in a box nobody may type in: a greyed-out field invites somebody to try to
 * change it and then teaches them the screen refuses things, and the same words read
 * better as a sentence than as three empty-looking fields.
 */
class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The words that go out')
                    ->description(fn (NotificationTemplate $record): string => self::whatMayBeUsed($record))
                    ->schema([
                        TextInput::make('subject')
                            ->label('Subject line')
                            ->helperText('What somebody sees in their inbox before they open it.')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages([
                                'required' => 'A message needs a subject line.',
                            ]),

                        Textarea::make('body')
                            ->label('Message')
                            ->rows(18)
                            ->required()
                            ->validationMessages([
                                'required' => 'A message needs something in it.',
                            ]),
                    ]),
            ]);
    }

    /**
     * The list of things this message can put a real name, number or amount into.
     *
     * Above the boxes rather than below them, because somebody writing a sentence needs
     * to know what they may drop into it before they have finished typing it. A name
     * written in a different shape from these, or one from another message, is left out
     * of what goes out rather than sent as it stands — so this list is the difference
     * between a message that reads properly and one with a gap in the middle of it.
     */
    private static function whatMayBeUsed(NotificationTemplate $record): string
    {
        $tokens = $record->tokens();

        if ($tokens === []) {
            return 'This message has nothing to fill in.';
        }

        return 'Anywhere in either box you can write '.self::asAList($tokens)
            .' and the real value is put in when the message goes out. Written any other way, or '
            .'taken from a different message, it is left out instead.';
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function asAList(array $tokens): string
    {
        $written = array_map(fn (string $token): string => '{{ '.$token.' }}', $tokens);
        $last = array_pop($written);

        return $written === [] ? $last : implode(', ', $written).' or '.$last;
    }
}
