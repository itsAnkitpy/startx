<?php

namespace App\Models;

use App\Exceptions\NotificationTemplateRefused;
use App\Notifications\TemplateCatalogue;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One client company's own wording for one of the messages this product sends.
 *
 * The row holds only what a client may change — the subject line and the body. What the
 * message is called, who it goes to, what sends it, which tokens it may use and whether
 * it skips somebody's daily bundle all live in {@see TemplateCatalogue}, because none of
 * those are a client's to change.
 *
 * `reworded` is not a field a form may fill either. It says whether this client wrote
 * these words themselves, and it is set by the act of changing them rather than typed.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped from
 * the client company in scope. So is `key`, for a sharper reason — it names which of our
 * messages this row supplies the words for, and letting a form carry it would let
 * somebody repoint their reminder wording at the message that tells a leaver money has
 * been claimed against them.
 *
 * **Nothing here deletes and nothing switches off.** A step sends a message by naming it,
 * so a missing row is a step with no words; and a switch that silenced a message would be
 * the one thing this module refuses outright — a person chooses when they are told, never
 * whether, because a chase-up nobody reads is how a client finds out at a tribunal. That
 * is why there is no `active` column here even though every other list a client keeps has
 * one.
 */
#[Fillable(['subject', 'body'])]
class NotificationTemplate extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reworded' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $template): void {
            throw NotificationTemplateRefused::deletion($template);
        });

        // Two different mistakes, and neither is caught by the index that keeps one row
        // per message per client. A row naming a message this product does not send is a
        // set of words nothing will ever use; a row moved onto a different message is one
        // message's words quietly becoming another's.
        //
        // No database check listing the keys, deliberately. It would be a second copy of
        // the catalogue that drifts from the first the day somebody renames a message,
        // and the only thing that writes a key is the seeder beside the catalogue itself.
        static::creating(function (self $template): void {
            if ($template->definition() === null) {
                throw NotificationTemplateRefused::unknownMessage((string) $template->key);
            }
        });

        static::updating(function (self $template): void {
            if ($template->isDirty('key')) {
                throw NotificationTemplateRefused::keyChanged($template);
            }

            // Changing the words is what makes them the client's, wherever the change
            // comes from — the screen, a seeder, an import written later. Recorded here
            // rather than at each of those, so none of them can forget. Putting our
            // wording back sets it itself, and says so by having already changed it.
            if ($template->isDirty(['subject', 'body']) && ! $template->isDirty('reworded')) {
                $template->reworded = true;
            }
        });
    }

    /**
     * What this message is called, who reads it, what sends it, and the tokens it may
     * use — read from the catalogue rather than stored beside the wording.
     *
     * @return array{name: string, goes_to: string, sent_when: string, bypasses_digest: bool, tokens: list<string>, subject: string, body: string}|null
     */
    public function definition(): ?array
    {
        return TemplateCatalogue::definitionOf($this->key);
    }

    public function name(): string
    {
        return $this->definition()['name'] ?? $this->key;
    }

    public function goesTo(): string
    {
        return $this->definition()['goes_to'] ?? '';
    }

    public function sentWhen(): string
    {
        return $this->definition()['sent_when'] ?? '';
    }

    /**
     * Whether this message goes out the moment it is due even to somebody who would
     * rather have a daily bundle. Read in step 5, where the bundle is built.
     */
    public function bypassesDigest(): bool
    {
        return $this->definition()['bypasses_digest'] ?? false;
    }

    /**
     * The tokens this message's wording may use, in the order they read best on a screen.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        return $this->definition()['tokens'] ?? [];
    }
}
