<?php

namespace App\Notifications;

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * One email a day holding everything that waited for it, for whoever chose that on their
 * own account page.
 *
 * **Nothing is stored to know a summary is due.** What waits is a message on record as
 * waiting; a person's summary is owed once their hour has come round on their own clock
 * since it was written. So a summary missed while nothing was sending goes on the next pass,
 * a message written after six waits for tomorrow's, and somebody who goes back to being told
 * at once gets what was waiting in one email straight away.
 */
final class DailySummary
{
    /** Workday caps its own daily bundle at fifty, and so does this (module 06, finding 1). */
    public const AtMost = 50;

    public function __construct(private readonly Sender $sender = new Sender) {}

    /**
     * Every summary owed right now in the client company in scope, each ready to be sent
     * on its own, the same as the chases the scheduled pass sends beside them.
     *
     * @return list<Closure(): mixed>
     */
    public function owed(): array
    {
        $waiting = NotificationDelivery::query()
            ->where('status', NotificationDelivery::Waiting)
            ->with('recipient')
            ->orderBy('id')
            ->get();

        if ($waiting->isEmpty()) {
            return [];
        }

        $company = Tenant::query()->findOrFail(TenantContext::id());

        return $waiting
            ->groupBy('recipient_user_id')
            ->map(function (Collection $messages) use ($company): ?Closure {
                $person = $messages->first()->recipient;
                $since = $this->lastDue($person, $company);
                $due = $messages->filter(fn (NotificationDelivery $message) => $message->created_at->lessThan($since))->values();

                return $due->isEmpty() ? null : fn () => $this->send($person, $company, $due);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The last time this person's hour came round on their own clock, else their company's,
     * or now for somebody who has gone back to being told at once.
     */
    private function lastDue(User $person, Tenant $company): CarbonImmutable
    {
        if ($person->daily_summary_hour === null) {
            return CarbonImmutable::now();
        }

        $now = CarbonImmutable::now($person->timezone ?? $company->timezone);
        $today = $now->setTime($person->daily_summary_hour, 0);

        return ($today->greaterThan($now) ? $today->subDay() : $today)->utc();
    }

    /**
     * Send one person's summary, and mark every message in it with how that went.
     *
     * Each message is its own subject line and the case it is about. Past fifty the rest
     * are counted rather than listed, and marked as sent in it all the same: tomorrow's
     * summary carrying today's leftovers is how it would grow past being read.
     *
     * ponytail: a server dying between the mail server taking the summary and the marking
     * below sends it again on the next pass. Mark the messages first if that ever happens.
     *
     * @param  Collection<int, NotificationDelivery>  $messages
     */
    private function send(User $person, Tenant $company, Collection $messages): ?NotificationDelivery
    {
        $more = $messages->count() - self::AtMost;

        $summary = $this->sender->sendTheDailySummary($person, [
            'person.name' => $person->name,
            'items' => $messages->take(self::AtMost)
                ->map(fn (NotificationDelivery $message) => $message->subject."\n".$company->addressFor('/admin/cases/'.$message->case_id))
                ->join("\n\n"),
            'items.more' => $more > 0 ? "And {$more} more." : '',
            'queue.link' => $company->addressFor('/admin/my-queue'),
        ]);

        if ($summary instanceof NotificationDelivery) {
            NotificationDelivery::query()->whereKey($messages->modelKeys())->update([
                'digest_batch_id' => $summary->getKey(),
                'status' => $summary->status,
                'sent_at' => $summary->sent_at,
                'failure_reason' => $summary->failure_reason,
            ]);
        }

        return $summary;
    }
}
