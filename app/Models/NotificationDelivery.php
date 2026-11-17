<?php

namespace App\Models;

use App\Notifications\TemplateCatalogue;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message this product sent about one case, in the words it actually went out in.
 *
 * **The subject and the body on this row are the record, not a copy of a template.** A
 * client may rewrite their chase-up whenever they like, so a case that looked its wording
 * up afterwards would let them quietly rewrite what they told somebody two years ago.
 * Every other fact on a case is frozen; this was the one that was not.
 *
 * **The step is named by its number, never by a step row.** The step that most needs
 * chasing is the one nobody has opened, and module 02 writes no row for such a step.
 *
 * @see TemplateCatalogue for what each message is called and what sends it
 */
#[Fillable([
    'case_id', 'step_sequence', 'recipient_user_id', 'recipient_address', 'triggered_by',
    'template_key', 'channel', 'subject', 'body', 'status',
])]
class NotificationDelivery extends Model
{
    use BelongsToTenant;

    /**
     * Held for the recipient's daily summary, then written, sent, and never got there.
     * Enforced by the table as well.
     */
    public const Waiting = 'waiting';

    public const Sending = 'sending';

    public const Sent = 'sent';

    public const Failed = 'failed';

    /** The only channel at launch. On the row because the record of a send needs it. */
    public const Email = 'email';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /** Null on one message only: the daily summary, which is about several cases. */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessCase::class, 'case_id');
    }

    /** Null for somebody with no account here, whose address is on the row regardless. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** Whoever pressed the chase button. Null on everything the product sent by itself. */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * What this message is called, read from the catalogue rather than stored beside the
     * wording — the same rule the wording row follows, for the same reason.
     */
    public function name(): string
    {
        return TemplateCatalogue::anyMessage($this->template_key)['name'] ?? $this->template_key;
    }

    /**
     * Why it went out, in the catalogue's own sentence.
     *
     * There is no `reason` column behind this. Each of the reasons this module's plan
     * listed names exactly one message, so a column would be the message's key spelled a
     * second way and free to disagree with it — and "The moment the case is opened" reads
     * better on a screen than the word `transition` ever would have.
     */
    public function whyItWentOut(): string
    {
        // A chase somebody pressed names them, which is what keeps it a person chasing a
        // person rather than an anonymous system mail.
        if ($this->triggeredBy instanceof User) {
            return $this->triggeredBy->name.' pressed the chase button';
        }

        return TemplateCatalogue::anyMessage($this->template_key)['sent_when'] ?? '';
    }

    /**
     * Whether it got there, in words that claim only what we actually know.
     *
     * **It says "Sent", never "Delivered", and that is deliberate.** Every mail provider
     * that documents its own events keeps two separate facts: the message left our
     * outbound server, and the recipient's server accepted it. Only a webhook back from a
     * provider ever proves the second, and this product has none. ServiceNow's own
     * community carries the complaint that follows from blurring them — mail marked sent
     * that never reached anybody. Checked 7 September 2026.
     */
    public function howItWent(): string
    {
        return match ($this->status) {
            self::Sent => $this->digest_batch_id === null ? 'Sent' : 'Sent in their daily summary',
            self::Failed => 'Did not send',
            self::Waiting => 'Waiting for their daily summary',
            default => 'Still sending',
        };
    }
}
