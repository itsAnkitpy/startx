<?php

namespace App\Exceptions;

use App\Models\NotificationTemplate;
use App\Notifications\TemplateCatalogue;
use RuntimeException;

/**
 * A refusal made by one client company's own wording for a message this product sends.
 *
 * Both refusals protect the same thing: a message a step names has to have words behind
 * it, and they have to be the words for that message. There is no refusal here for
 * duplicate wording of the same message, because the database's own index handles it —
 * one row per message per client, which a seeder, an import or a screen written later
 * cannot get around.
 */
class NotificationTemplateRefused extends RuntimeException
{
    /**
     * The wording of a message cannot be deleted.
     *
     * A step sends a message by naming it, so a row that has gone is a step that reaches
     * the moment it should send something and has nothing to send — the invisible failure
     * this whole module exists to remove. There is nothing to switch off to instead, and
     * that is deliberate: a person chooses when they are told, never whether.
     *
     * The same limit as every other refusal of this kind in this codebase, worth stating
     * rather than assumed: it covers deleting one row, which is what a screen's button
     * does. A delete written against the whole table at once fires no model event and
     * walks straight past this.
     */
    public static function deletion(NotificationTemplate $template): self
    {
        return new self(
            "The wording of [{$template->name()}] cannot be deleted. A step sends this message by "
            .'naming it, so a client with no wording for it is a step that sends nothing, and '
            .'silencing a message is not something this product allows.'
        );
    }

    /**
     * Existing wording cannot be moved onto a different message.
     *
     * A row's key names which of our messages it supplies the words for. Changing it on a
     * row that already exists does not create anything or lose anything, which is why
     * neither the index nor the delete refusal catches it — it quietly makes one message's
     * wording into another's. A client editing a reminder would find their words going out
     * to a leaver as the notice that money had been claimed against them.
     */
    public static function keyChanged(NotificationTemplate $template): self
    {
        $was = (string) $template->getOriginal('key');

        return new self(
            "This wording belongs to [{$was}] and cannot be moved onto another message. Which "
            .'message a row supplies the words for is set when a client company is created and '
            .'never changes afterwards.'
        );
    }

    /**
     * A row names a message this product does not send.
     *
     * Only the seeder writes a key, so this is reached by a seeder or a test naming one
     * that has been renamed or removed from the catalogue. Refused here rather than left
     * to be discovered at the moment of sending, which is where it would otherwise turn
     * up: a step reaching a send with no words behind it.
     *
     * @see TemplateCatalogue::definitions() for the messages this product does send
     */
    public static function unknownMessage(string $key): self
    {
        $known = implode(', ', array_keys(TemplateCatalogue::definitions()));

        return new self(
            "This product does not send a message called [{$key}], so there is nothing for a "
            ."client's wording of it to be used by. The messages it does send are: {$known}."
        );
    }
}
