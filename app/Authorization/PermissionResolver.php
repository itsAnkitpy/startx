<?php

namespace App\Authorization;

use App\Models\OrgUnit;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\JoinClause;

/**
 * The one place "may this person perform this action, on this record" is answered.
 * Read the person's grants, walk up the structure from the record's unit, answer.
 *
 * Note what it is never asked: which role somebody holds. A check written against a
 * role's name breaks for the first client who renames it and breaks worse for the
 * first client who splits that job across two roles. Every caller asks about an
 * action.
 *
 * Answers are remembered per client company and per person, for the life of one
 * request or one queued job — this class is bound as a scoped instance, which Laravel
 * discards between requests and between jobs.
 *
 * The client company is in the key rather than the cache being cleared when the
 * company in scope changes. Clearing on switch works only while every future entry
 * point remembers to do it, and the failure is silent and crosses clients, which is
 * the one failure this whole module exists to prevent. With the company in the key,
 * forgetting is impossible: another company's question simply finds no entry and
 * resolves properly. Module 06's scheduled pass loops over client companies inside one
 * process, so this is not a hypothetical.
 *
 * Keeping the memory to one request also removes stale permissions for free: Filament
 * re-runs authorization on every Livewire request, so a grant revoked while somebody
 * is signed in takes effect on their next click with nothing to invalidate.
 */
class PermissionResolver
{
    /** @var array<string, bool> */
    private array $answers = [];

    /** @var array<string, list<object{org_unit_id: int|null, includes_descendants: bool}>> */
    private array $grants = [];

    /** @var array<string, list<int>> */
    private array $ancestors = [];

    /** @var array<string, list<int>|null> */
    private array $reachable = [];

    /**
     * @param  OrgUnit|null  $unit  The unit the record being acted on sits in. Null asks
     *                              whether the person holds the action anywhere at all,
     *                              which is the right question for opening a list — the
     *                              rows in the list are each checked in turn.
     */
    public function allows(User $user, string $permission, ?OrgUnit $unit = null): bool
    {
        $key = implode('|', [
            TenantContext::id() ?? '-',
            $user->getKey(),
            $permission,
            $unit?->getKey() ?? '*',
        ]);

        return $this->answers[$key] ??= $this->resolve($user, $permission, $unit);
    }

    /**
     * Which parts of the structure this person may act on for this action.
     *
     * `null` means everywhere in the client company — either the grant names no unit, or
     * there is no structure in the way. An empty list means nowhere, which is the honest
     * answer for somebody holding the action on no grant at all.
     *
     * This exists because {@see allows()} with no unit answers "anywhere at all", which is
     * the right question for opening a screen and the wrong one for filling it. A list
     * that trusts the screen check shows an HR head responsible for one branch every
     * person in the company. Each list applies this to its own rows, because the column
     * that says which unit a row sits in differs — a department is its own unit, a person
     * reaches one through their current job.
     *
     * @return list<int>|null
     */
    public function reachableUnitIds(User $user, string $permission): ?array
    {
        $key = (TenantContext::id() ?? '-').'|'.$user->getKey().'|'.$permission;

        if (array_key_exists($key, $this->reachable)) {
            return $this->reachable[$key];
        }

        $ids = [];

        foreach ($this->grantsCarrying($user, $permission) as $grant) {
            if ($grant->org_unit_id === null) {
                return $this->reachable[$key] = null;
            }

            $unit = OrgUnit::query()->find($grant->org_unit_id);

            if ($unit === null) {
                continue;
            }

            // Granted on one unit reaches that unit alone unless the grant was told to
            // reach downwards — the same rule allows() applies, read the other way round.
            $ids = [...$ids, ...($grant->includes_descendants
                ? $unit->selfAndDescendantIds()
                : [(int) $unit->getKey()])];
        }

        return $this->reachable[$key] = array_values(array_unique($ids));
    }

    /**
     * Whether this person may perform the action over the whole client company rather
     * than over some part of it.
     *
     * Written here so that no caller has to know which of the two answers from
     * {@see reachableUnitIds()} means everywhere — the one place that has already been
     * got wrong is the one worth not repeating.
     */
    public function allowsEverywhere(User $user, string $permission): bool
    {
        return $this->reachableUnitIds($user, $permission) === null;
    }

    /**
     * Forget everything remembered so far. Needed only where a grant is changed and the
     * same request then asks again — a test, or a screen that grants a role and
     * immediately re-renders.
     */
    public function forget(): void
    {
        $this->answers = [];
        $this->grants = [];
        $this->ancestors = [];
        $this->reachable = [];
    }

    private function resolve(User $user, string $permission, ?OrgUnit $unit): bool
    {
        $grants = $this->grantsCarrying($user, $permission);

        if ($grants === []) {
            return false;
        }

        foreach ($grants as $grant) {
            // No unit on the grant means the whole client company, so the record's
            // position in the structure does not matter.
            if ($grant->org_unit_id === null) {
                return true;
            }
        }

        if ($unit === null) {
            return true;
        }

        $unitId = (int) $unit->getKey();
        $above = $this->ancestorIdsOf($unit);

        foreach ($grants as $grant) {
            $grantedUnitId = (int) $grant->org_unit_id;

            // Granted on this very unit — the reach-downwards flag is irrelevant.
            if ($grantedUnitId === $unitId) {
                return true;
            }

            // Granted higher up the structure. That only reaches this record if the
            // grant was told to reach downwards; otherwise "HR head for this one
            // branch" would silently cover every branch below it too.
            if ($grant->includes_descendants && in_array($grantedUnitId, $above, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every grant this person holds that carries this action, whichever of their roles
     * carries it.
     *
     * @return list<object{org_unit_id: int|null, includes_descendants: bool}>
     */
    private function grantsCarrying(User $user, string $permission): array
    {
        $key = (TenantContext::id() ?? '-').'|'.$user->getKey().'|'.$permission;

        return $this->grants[$key] ??= RoleAssignment::query()
            ->join('role_permissions', function (JoinClause $join): void {
                $join->on('role_permissions.role_id', '=', 'role_assignments.role_id')
                    ->on('role_permissions.tenant_id', '=', 'role_assignments.tenant_id');
            })
            ->where('role_assignments.user_id', $user->getKey())
            ->where('role_permissions.permission', $permission)
            ->distinct()
            ->toBase()
            ->get(['role_assignments.org_unit_id', 'role_assignments.includes_descendants'])
            ->map(fn (object $row) => (object) [
                'org_unit_id' => $row->org_unit_id === null ? null : (int) $row->org_unit_id,
                'includes_descendants' => (bool) $row->includes_descendants,
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ancestorIdsOf(OrgUnit $unit): array
    {
        $key = (TenantContext::id() ?? '-').'|'.$unit->getKey();

        return $this->ancestors[$key] ??= $unit->ancestorIds();
    }
}
