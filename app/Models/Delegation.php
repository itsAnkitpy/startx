<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DelegationFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rakesh is away for a fortnight and Priya holds his exits while he is.
 *
 * A row here does not move anything. Resolution reads it every time it works out whose
 * job a step is, which is what makes a cover start and stop on its own dates with no job
 * to run and nothing to repair afterwards — the same reason nothing else in this module is
 * stored either.
 *
 * It adds Priya to the people a step belongs to rather than taking Rakesh off it, and that
 * is a deliberate choice recorded in the module's plan: a person who is away but reading
 * their mail should not be locked out of their own work, and it removes the question of
 * what a cover naming somebody unusable falls back to. Everything the plan asks of a cover
 * is still true — when the dates run out Priya stops being resolved, and Rakesh is
 * resolved exactly as if there had never been a cover.
 */
#[Fillable(['user_id', 'delegate_id', 'process_key', 'effective_from', 'effective_to'])]
class Delegation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DelegationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $cover): void {
            $cover->refusePassingCoverOn();
        });
    }

    /** The person going away. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whoever holds their queue while they are away. */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /**
     * Covers running on one day, both ends counted in — the same shape and the same
     * convention as a job row's own as-of read.
     */
    public function scopeAsOf(Builder $query, DateTimeInterface|string $date): void
    {
        $on = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        $query->where('effective_from', '<=', $on)
            ->where('effective_to', '>=', $on);
    }

    /**
     * A cover cannot be passed on to a third person.
     *
     * Priya, holding Rakesh's exits, cannot name Chandni to hold them next. Workday and
     * SAP both forbid this deliberately, and the reason is that a chain of covers is a
     * chain nobody can read back — the person accountable for an approval is meant to be
     * findable in one hop, not walked to.
     *
     * Refused here rather than in a screen, so an import, a command or module 07's form
     * all meet the same rule. Resolution never chains either, because it looks one hop and
     * stops, so a row that got in some other way still cannot deliver a step two people
     * along.
     *
     * Both ends of the chain are refused, because a chain can be written from either end
     * and the two orderings leave the client in the same place. Priya cannot name Chandni
     * while she is holding Rakesh's exits; and Rakesh cannot name Priya over dates when
     * Priya is herself away and being covered. Refusing only the first would let the
     * second be written and would leave Rakesh's exits sitting with two people who are
     * both on holiday, reported as covered.
     *
     * Only where the dates actually overlap. Priya covering Rakesh in the first fortnight
     * of August has not given up her own right to be covered in the last week of it, and a
     * rule that said otherwise would quietly retire people from being able to go away.
     */
    private function refusePassingCoverOn(): void
    {
        $wouldBeAChain = static::query()
            ->where('process_key', $this->process_key)
            ->where('effective_from', '<=', $this->effective_to)
            ->where('effective_to', '>=', $this->effective_from)
            ->where(fn (Builder $eitherEnd) => $eitherEnd
                ->where('delegate_id', $this->user_id)
                ->orWhere('user_id', $this->delegate_id))
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($wouldBeAChain) {
            throw ProcessRefused::coverCannotBePassedOn($this->process_key);
        }
    }
}
