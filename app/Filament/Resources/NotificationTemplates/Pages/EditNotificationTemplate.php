<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    public function getTitle(): string
    {
        /** @var NotificationTemplate $template */
        $template = $this->getRecord();

        return $template->name();
    }

    public function getSubheading(): string
    {
        /** @var NotificationTemplate $template */
        $template = $this->getRecord();

        return 'Goes to '.lcfirst($template->goesTo()).'. Sent when: '.lcfirst($template->sentWhen()).'.';
    }

    /**
     * No delete action, and one action of our own: putting our wording back.
     *
     * A client who has rewritten a message and wants out of it has nowhere to go
     * otherwise — the words we shipped are not on the screen any more once theirs are
     * saved over them, and asking us for them is the kind of call this product is sold on
     * not needing. It only appears once they have actually changed something.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->putOurWordingBack(),
        ];
    }

    private function putOurWordingBack(): Action
    {
        return Action::make('putOurWordingBack')
            ->label('Put our wording back')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->reworded)
            ->requiresConfirmation()
            // Said out loud, the same as saving the form does. Without it the words
            // change under somebody who pressed a button and nothing tells them it
            // worked, which is how a person presses it twice.
            ->successNotificationTitle('Our wording is back.')
            ->modalHeading('Put our wording back')
            ->modalDescription('This replaces your version of this message with the one we ship. '
                .'Your own wording is not kept anywhere afterwards.')
            ->modalSubmitActionLabel('Put it back')
            ->action(function (): void {
                /** @var NotificationTemplate $template */
                $template = $this->getRecord();
                $ours = $template->definition();

                if ($ours === null) {
                    return;
                }

                // Set rather than filled, because whether the words are the client's own
                // is not one of the two fields a form may write — and clearing it here is
                // also what stops the record deciding for itself that these new words are
                // theirs, which is what it does for every other change.
                $template->reworded = false;
                $template->subject = $ours['subject'];
                $template->body = $ours['body'];
                $template->save();

                $this->fillForm();
            });
    }
}
