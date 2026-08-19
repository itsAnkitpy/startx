<?php

namespace App\Models;

use App\Exceptions\OrgUnitCycle;
use App\Tenancy\BelongsToTenant;
use Database\Factories\OrgUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One unit of a client company's structure. The whole structure is this one table
 * pointing at itself, so the number of levels and what they are called are the
 * client's choice rather than ours.
 *
 * `type` is the client's own word for the level — company, business line and
 * sub-business line for a three-level client; company, region, state, plant and team for a
 * five-level one. Nothing in the schema knows the difference.
 *
 * A name is not an identifier: real structures repeat a name across sibling units.
 *
 * `tenant_id` is deliberately absent from the fields a form may fill: it is stamped
 * from the client company in scope.
 */
#[Fillable(['parent_id', 'type', 'name', 'code', 'active'])]
class OrgUnit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrgUnitFactory> */
    use HasFactory;

    /**
     * How far the walking queries below will follow the tree. A structure is three or
     * four levels deep in practice; this is a stop so that a loop written straight into
     * the database by hand cannot make a query run forever.
     */
    private const MAX_DEPTH = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $unit): void {
            $unit->refuseCycle();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The top of the structure — the units with nothing above them.
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Every unit above this one, the immediate parent first and the top of the
     * structure last. Each carries a `depth` of 1 for the parent, 2 for its parent,
     * and so on.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        return $this->walk(
            'select p.*, above.depth + 1 as depth
               from org_units p
               join above on p.id = above.parent_id
              where p.tenant_id = ? and above.depth < ?',
            'above',
            'order by depth'
        );
    }

    /**
     * Every unit below this one, nearest level first. Each carries a `depth` of 1 for a
     * direct child, 2 for a grandchild, and so on.
     *
     * @return Collection<int, self>
     */
    public function descendants(): Collection
    {
        return $this->walk(
            'select c.*, below.depth + 1 as depth
               from org_units c
               join below on c.parent_id = below.id
              where c.tenant_id = ? and below.depth < ?',
            'below',
            'order by depth, name'
        );
    }

    /**
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        return $this->ancestors()->map(fn (self $unit) => (int) $unit->getKey())->all();
    }

    /**
     * @return list<int>
     */
    public function descendantIds(): array
    {
        return $this->descendants()->map(fn (self $unit) => (int) $unit->getKey())->all();
    }

    /**
     * This unit and everything below it — what a role granted on a unit and reaching
     * downwards covers.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        return [(int) $this->getKey(), ...$this->descendantIds()];
    }

    public function isDescendantOf(self|int $unit): bool
    {
        $id = $unit instanceof self ? (int) $unit->getKey() : $unit;

        return in_array($id, $this->ancestorIds(), true);
    }

    /**
     * Walk the tree in one recursive query.
     *
     * The walk is written in raw SQL, which is exactly the case the database policy
     * exists for: an Eloquent scope would not reach inside it.
     *
     * What actually keeps the walk inside one client company is that every parent link
     * carries the company, so a unit can only ever point at a parent in its own. Naming
     * the company in the query as well is a second belt for the day someone weakens that
     * key — no test can tell the two apart while the key is in place, and the schema
     * check is what catches the key changing.
     *
     * @return Collection<int, self>
     */
    private function walk(string $recursiveTerm, string $cte, string $order): Collection
    {
        $tenantId = (int) $this->getAttribute('tenant_id');

        $rows = DB::select(
            "with recursive {$cte} as (
                 select u.*, 0 as depth
                   from org_units u
                  where u.tenant_id = ? and u.id = ?
                 union all
                 {$recursiveTerm}
             )
             select * from {$cte} where depth > 0 {$order}",
            [$tenantId, (int) $this->getKey(), $tenantId, self::MAX_DEPTH]
        );

        return self::hydrate($rows);
    }

    /**
     * Refuse a change that would put this unit under itself, directly or through any
     * number of levels. Checked against the tree as it currently stands, which is what
     * the database still holds at this point.
     *
     * The database refuses the one-step version of this on its own. Everything longer
     * needs to see the tree, so it is checked here — which means a loop written by hand
     * in raw SQL is not covered.
     */
    private function refuseCycle(): void
    {
        $parentId = $this->getAttribute('parent_id');

        if ($parentId === null || ! $this->exists || ! $this->isDirty('parent_id')) {
            return;
        }

        $parentId = (int) $parentId;

        if ($parentId === (int) $this->getKey()) {
            throw OrgUnitCycle::underItself((string) $this->getAttribute('name'));
        }

        $parent = self::query()->find($parentId);

        if ($parent !== null && in_array($parentId, $this->descendantIds(), true)) {
            throw OrgUnitCycle::underOwnDescendant(
                (string) $this->getAttribute('name'),
                (string) $parent->getAttribute('name'),
            );
        }
    }
}
