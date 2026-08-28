<?php

namespace App\Process;

use App\Models\CaseEvent;
use App\Models\Delegation;
use App\Models\EmploymentRecord;
use App\Models\OrgUnit;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whose job a step is, worked out every time somebody asks.
 *
 * Nothing here is stored. A step's `assignee_rule` names one of six ways to find its
 * people and this turns that into accounts, at the moment a queue is read and again at
 * the moment somebody acts. The alternative — writing the names down when the step opens
 * — needs a nightly job to repair itself every time somebody changes role, goes on leave
 * or leaves the company, which is precisely the population this class exists to handle.
 * Oracle Fusion ships that job and its documentation says it must run daily; Camunda
 * resolves on read as this does.
 *
 * **The answer is a set, not a person.** The whole IT team clearing an exit all see the
 * step and the first to act claims it; a single-approver step is a set of one and behaves
 * identically. Nothing here decides who *wins* a contested step — that is the claim, and
 * it belongs to the engine next door.
 *
 * **An empty set is a real answer and it is never a pass.** A step nobody holds stays
 * open, warns on the case's own record, and cannot complete, skip or approve itself. That
 * is a deliberate difference from the two largest workflow products: ServiceNow skips an
 * approval whose group is empty and its skipped state follows the *approved* path, and
 * Workday marks a step whose candidates are all excluded as not required and drops it
 * from the process. In an exit either behaviour is a settlement paid out against a
 * clearance no person ever performed.
 *
 * How the falling-back works, and it is the same shape for every kind: each way of
 * finding people produces candidates in levels, nearest first. A level is used when
 * anybody in it can actually act — their account is live, and they are not the person the
 * case is about. Otherwise the next level up is tried, which is what "escalate one level"
 * means for a vacant role and for a person who would otherwise approve their own exit.
 * When no level yields anybody the client's stand-in holds the step, and when there is no
 * usable stand-in either the step is held by nobody at all. Both of those last two are
 * warned about on the case.
 */
final class AssigneeResolver
{
    /**
     * The six ways a step finds its people. Allow-listed rather than an expression
     * language, so a client's administrator can be shown a list of six things in module
     * 12's screen instead of being handed a syntax.
     */
    public const Kinds = [
        'reporting_manager',
        'initiators_manager',
        'role_in_scope',
        'role_global',
        'specific_user',
        'external',
    ];

    /**
     * The account a step falls to when nobody holds the role it asked for. The first
     * switch in this product declared by a module that reads one — see
     * {@see AppServiceProvider::boot()}, which is where it is declared.
     *
     * It holds an account's id as a whole number because the declared kinds are a flag,
     * a whole number and text, and none of them is "an account". A number is honest about
     * what is stored, and every way it can go stale — the account deleted, deactivated,
     * belonging to another client, or being the very person the case is about — is
     * already handled by the same filter every other candidate passes through.
     */
    public const StandInSetting = 'vacant_role_standin';

    /** What the case's own record calls a step nobody holds. */
    public const NobodyHoldsItEvent = 'step_has_nobody';

    /**
     * How far up a reporting line the walk will climb. A line is a handful of people deep
     * in practice; this is a stop so a loop written straight into the database by hand
     * cannot make the walk run forever, the same limit and the same reason as the loop
     * check on a job row.
     */
    private const LongestReportingLine = 50;

    /**
     * The people who may act on this step right now.
     *
     * Empty means nobody may, and the case's record says so. It never means the step is
     * finished, approved or skipped.
     *
     * `$itsTimeHasRunOut` is the only thing that widens the answer. A step past its own
     * target adds whoever its `escalate_to` rule names **beside** the people it already
     * belonged to, and takes nobody away — so an overdue clearance cannot be escaped by
     * escalating it, and the person who was always meant to do it is still the person the
     * record names as having been asked. Whoever reads the queue passes it in, because
     * whether a target has run out is a fact about the clock rather than about the rule,
     * and this class has never looked at a clock.
     *
     * @return Collection<int, User>
     */
    public function resolve(ProcessCase $case, ProcessStep $step, bool $itsTimeHasRunOut = false): Collection
    {
        // A step for somebody with no account has no resolved set at all, and that is the
        // point of it rather than a gap in it: permission to act is the signed link sent
        // to their address, checked on every submission, and nothing else. So it must not
        // fall to the stand-in — handing a candidate's own form to a client's HR manager
        // would record their answers against an employee who never typed them.
        if ($this->isForSomebodyWithNoAccount($step)) {
            return new Collection;
        }

        $subjectId = $case->subject_user_id === null ? null : (int) $case->subject_user_id;

        $people = $this->nearestLevelThatCanAct($case, (array) $step->assignee_rule, $subjectId)
            ?? $this->theStandIn($case, $step, $subjectId);

        if (! $itsTimeHasRunOut) {
            return $people;
        }

        return $people
            ->concat($this->whoItEscalatesTo($case, $step, $subjectId))
            ->unique(fn (User $person) => (int) $person->getKey())
            ->values();
    }

    /**
     * Whoever a step widens to once its own target has run out.
     *
     * The same six ways of finding people, read from `escalate_to` instead of from
     * `assignee_rule`, which is why there is no seventh mechanism here and no second
     * vocabulary for a client to learn.
     *
     * **No stand-in at the end of it.** When the escalation rule finds nobody the step
     * simply does not widen — it is still held by the people it was always held by, and
     * handing an overdue clearance to the company's stand-in on top of them would put a
     * branch matter in front of head office for no reason other than lateness. The
     * stand-in exists for a step nobody holds, and this is not one.
     *
     * @return Collection<int, User>
     */
    private function whoItEscalatesTo(ProcessCase $case, ProcessStep $step, ?int $subjectId): Collection
    {
        $rule = (array) ($step->escalate_to ?? []);

        if ($rule === [] || ($rule['kind'] ?? null) === 'external') {
            return new Collection;
        }

        return $this->nearestLevelThatCanAct($case, $rule, $subjectId) ?? new Collection;
    }

    /**
     * The first level of candidates with anybody usable in it, or null when no level has.
     *
     * Null and an empty collection are different answers here and the difference is what
     * the stand-in hangs off: nothing found at any level is the vacancy the stand-in
     * exists for, and an escalation finding nothing is simply a step that does not widen.
     *
     * @param  array<string, mixed>  $rule
     * @return Collection<int, User>|null
     */
    private function nearestLevelThatCanAct(ProcessCase $case, array $rule, ?int $subjectId): ?Collection
    {
        foreach ($this->levelsFor($case, $rule) as $level) {
            $people = $this->whichOfThemCanAct($level, $subjectId);
            $people = $this->withWhoeverIsCoveringThem($people, $case, $subjectId);

            if ($people->isNotEmpty()) {
                return $people;
            }
        }

        return null;
    }

    public function isForSomebodyWithNoAccount(ProcessStep $step): bool
    {
        return $step->participant_kind === 'external'
            || ($step->assignee_rule['kind'] ?? null) === 'external';
    }

    /**
     * Candidates in levels, nearest first, for whichever of the six ways this step uses.
     *
     * Each level is a list of account ids and nothing more, and they are produced one at
     * a time rather than all at once — the ordinary case is that the first level has
     * somebody in it, and then nothing further is read at all.
     *
     * No default: a kind this code does not know is a mistake in the process rather than a
     * case to be quietly answered with nobody, and publishing refuses one before a case
     * can ever run on it.
     *
     * @param  array<string, mixed>  $rule  one of the six, read from `assignee_rule` or from
     *                                      `escalate_to` — they are the same vocabulary
     * @return iterable<int, list<int>>
     */
    private function levelsFor(ProcessCase $case, array $rule): iterable
    {
        return match ($rule['kind'] ?? null) {
            'reporting_manager' => $this->reportingLineAbove($case->subject_user_id),
            'initiators_manager' => $this->reportingLineAbove($case->initiated_by),
            'role_in_scope' => $this->holdersOfARoleUpTheTree($case, $rule),
            'role_global' => $this->everyHolderOfARole((string) ($rule['role'] ?? '')),
            'specific_user' => $this->thePersonNamed((string) ($rule['email'] ?? '')),
        };
    }

    /**
     * The reporting line above somebody: their manager, then their manager's manager, one
     * level at a time.
     *
     * Each hop reads that person's most recent job row rather than only the row true
     * today, which is the same choice the loop check on a job row makes and for the same
     * reason: reading only current rows stops the walk dead at anybody who has left. With
     * Deepak gone and his team not yet moved onto his successor, his own manager is
     * exactly who an approval sitting on Deepak should climb to, and a walk that stopped
     * at him would send it to the client's stand-in instead.
     *
     * @return iterable<int, list<int>>
     */
    private function reportingLineAbove(int|string|null $personId): iterable
    {
        if ($personId === null) {
            return;
        }

        $personId = (int) $personId;

        for ($climbed = 0; $climbed < self::LongestReportingLine; $climbed++) {
            $managerId = EmploymentRecord::query()
                ->where('user_id', $personId)
                ->orderByDesc('effective_from')
                ->value('reports_to_id');

            if ($managerId === null) {
                return;
            }

            yield [(int) $managerId];

            $personId = (int) $managerId;
        }
    }

    /**
     * The named person, and then the reporting line above them.
     *
     * Named by work address because that is what a client types into a spreadsheet cell.
     * The line above them is there for the one case the plan insists on: a step naming the
     * very person the case is about can never be answered by that person, so it climbs,
     * and there is no version of it that skips.
     *
     * @return iterable<int, list<int>>
     */
    private function thePersonNamed(string $workEmail): iterable
    {
        $personId = User::query()->where('work_email', $workEmail)->value('id');

        if ($personId === null) {
            return;
        }

        yield [(int) $personId];

        yield from $this->reportingLineAbove($personId);
    }

    /**
     * Everybody holding a role anywhere in the client company, as one level. There is no
     * tree to climb, so a vacancy here goes straight to the stand-in.
     *
     * @return iterable<int, list<int>>
     */
    private function everyHolderOfARole(string $roleKey): iterable
    {
        $roleId = $this->idOfTheRoleCalled($roleKey);

        if ($roleId === null) {
            return;
        }

        yield RoleAssignment::query()->where('role_id', $roleId)->pluck('user_id')->all();
    }

    /**
     * Holders of a role over the part of the structure the case's subject sits in, then
     * over the unit above that, and so on up to the top. "The director of *this* business
     * line" is this walk, and it replaces matching email addresses against an attribute,
     * which is fragile, unauditable, and already the cause of a live defect in the tool
     * this one replaces.
     *
     * Two directions matter here and they are different questions. The walk goes **up**:
     * a branch with no HR head of its own is answered by the region's. A grant reaches
     * **down** only when it says it does, which is `includes_descendants` — so a grant
     * held over the whole business line answers for the branch inside it, while "HR head,
     * Pune branch" never starts answering for the company. Getting that backwards is the
     * one mistake here a client would never see and could never forgive.
     *
     * The first level is therefore wider than the unit itself: the unit's own grants, plus
     * grants held above it that reach down into it, plus grants held over the whole client
     * company, which is what an unset unit means. Levels after it are one ancestor each.
     *
     * The department read is the one frozen onto the case when it opened, not the person's
     * department today. An exit that moves somebody out of a branch on day two must not
     * also move the clearance it was opened with into a different branch's queue.
     *
     * @param  array<string, mixed>  $rule
     * @return iterable<int, list<int>>
     */
    private function holdersOfARoleUpTheTree(ProcessCase $case, array $rule): iterable
    {
        $roleId = $this->idOfTheRoleCalled((string) ($rule['role'] ?? ''));
        $unit = $case->subjectEmploymentRecord?->orgUnit ?? $this->theDepartmentTheCaseNamed($case, $rule);

        // Nothing to scope to and nothing the step named to scope by. There is no sensible
        // narrowing left, so it resolves to nobody and warns rather than quietly widening
        // to the whole company; a process that wants everybody holding a role says so with
        // `role_global`.
        if ($roleId === null || $unit === null) {
            return;
        }

        $ancestorIds = $unit->ancestorIds();

        yield RoleAssignment::query()
            ->where('role_id', $roleId)
            ->where(function (Builder $query) use ($unit, $ancestorIds): void {
                $query->whereNull('org_unit_id')
                    ->orWhere('org_unit_id', $unit->getKey())
                    ->orWhere(fn (Builder $reaching) => $reaching
                        ->whereIn('org_unit_id', $ancestorIds)
                        ->where('includes_descendants', true));
            })
            ->pluck('user_id')
            ->all();

        foreach ($ancestorIds as $ancestorId) {
            yield RoleAssignment::query()
                ->where('role_id', $roleId)
                ->where('org_unit_id', $ancestorId)
                ->pluck('user_id')
                ->all();
        }
    }

    /**
     * The department a case with no job row names in its own answers.
     *
     * A hiring request is about a vacancy, so there is no job row to read a department
     * from and the walk above has nothing to start from. Rather than sending the approval
     * to whoever holds the role anywhere in the company — which in a client with three
     * business lines puts one request in three inboxes and lets whoever clicks first take
     * it — the step may say **which answer on the case holds the department**, and the
     * walk starts there instead.
     *
     * It names no process, no role and no question: the step's own rule carries the name
     * of the answer, so a client writing a hiring request, a budget request or anything
     * else about nobody gets the same behaviour without a line of code.
     *
     * **The job row still wins where there is one.** An exit reads the department frozen
     * onto it on purpose, and an answer typed later must not move a clearance that was
     * opened against a branch. A vacancy has nothing frozen to read, so its department is
     * whatever the request currently says — and correcting the request after a send-back
     * moves the approval with it, which is right, because the vacancy really did move.
     *
     * A department nobody heads, or an answer naming nothing, leaves the step held by
     * nobody with a line on the case saying so. That is the existing behaviour of every
     * scoped step and it fails loudly rather than quietly.
     *
     * @param  array<string, mixed>  $rule
     */
    private function theDepartmentTheCaseNamed(ProcessCase $case, array $rule): ?OrgUnit
    {
        $question = $rule['department_from'] ?? null;

        if (! is_string($question) || $question === '') {
            return null;
        }

        $answer = $case->answersSoFar()[$question] ?? null;

        // The client's own departments and nothing else: row-level security is on the
        // table, so a number edited in a browser cannot reach another company's row.
        return is_numeric($answer) ? OrgUnit::query()->find((int) $answer) : null;
    }

    private function idOfTheRoleCalled(string $roleKey): ?int
    {
        $id = Role::query()->where('key', $roleKey)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Which of a level's candidates may actually act, and the only filter in this class.
     *
     * Two rules, both from the plan's own list of edge cases. A leaver's account is
     * treated as vacant, and needs no repairing anywhere: resolution runs on read, so a
     * dead account simply stops appearing. And the person a case is about is never one of
     * its approvers, however they were found — a clearance somebody grants themselves on
     * their own exit is the one signature this product cannot afford to record.
     *
     * @param  list<int>  $candidateIds
     * @return Collection<int, User>
     */
    private function whichOfThemCanAct(array $candidateIds, ?int $subjectId): Collection
    {
        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', $candidateIds),
            fn (int $id) => $id !== $subjectId,
        )));

        if ($candidateIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $candidateIds)
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Whoever is covering the people in this level, added beside them.
     *
     * Added rather than substituted. Rakesh being away does not mean Rakesh cannot act if
     * he looks at his mail, and it removes the question of what a cover naming somebody
     * unusable is supposed to fall back to — Priya passes the same filter everybody passes,
     * and if she fails it Rakesh is simply still there. Everything the plan asks of a cover
     * survives: the dates running out stops Priya being resolved, and Rakesh is then
     * resolved exactly as if there had never been a cover.
     *
     * **Cover is only read for people who survived the filter, and that ordering is the
     * rule rather than a convenience.** Somebody who has left the company does not resolve,
     * so nobody covers them either — an authority outliving the job it came from is the
     * thing this module refuses everywhere else, and SAP reverts an in-flight step to its
     * original holder for the same reason. The person a case is about does not resolve
     * either, so a cover cannot be used to clear their own exit on their behalf.
     *
     * **It looks one hop and stops.** A cover held by somebody who is themselves covered
     * does not travel: Chandni covering Priya, who is covering Rakesh, does not reach
     * Rakesh's steps. The model refuses writing that chain at all, and this is the half
     * that holds even for a row that arrived some other way.
     *
     * Each person added carries who they are covering, so an action taken under cover can
     * be recorded in both names without asking the question a second time.
     *
     * @param  Collection<int, User>  $people
     * @return Collection<int, User>
     */
    private function withWhoeverIsCoveringThem(Collection $people, ProcessCase $case, ?int $subjectId): Collection
    {
        if ($people->isEmpty()) {
            return $people;
        }

        $covers = Delegation::query()
            ->whereIn('user_id', $people->modelKeys())
            ->where('process_key', $case->template->key)
            ->asOf(CarbonImmutable::now())
            ->orderBy('user_id')
            ->get();

        if ($covers->isEmpty()) {
            return $people;
        }

        $away = $people->keyBy(fn (User $person) => (int) $person->getKey());

        $standingIn = $this->whichOfThemCanAct($covers->pluck('delegate_id')->all(), $subjectId)
            ->reject(fn (User $delegate) => $away->has((int) $delegate->getKey()))
            ->each(function (User $delegate) use ($covers, $away): void {
                // Whose queue this is. Somebody covering two people at once is answered by
                // the first of them, which keeps the record definite; a second name in the
                // same breath would read as one approval given twice.
                $for = $covers->first(
                    fn (Delegation $cover) => (int) $cover->delegate_id === (int) $delegate->getKey()
                );

                $delegate->setRelation('coveringFor', $away->get((int) $for->user_id));
            });

        return $people->concat($standingIn)->values();
    }

    /**
     * The client's stand-in, and the warning that says the step got this far.
     *
     * The stand-in passes through the same filter as everybody else, which is what makes
     * the four ways the setting can go stale cost no code at all: an id whose account was
     * never created finds nothing, an id belonging to another client is invisible inside
     * this client's scope, a deactivated account is filtered, and the stand-in who happens
     * to be the person leaving is dropped. Any of those leaves the step held by nobody,
     * which is still a warned, open step and never a pass.
     *
     * @return Collection<int, User>
     */
    private function theStandIn(ProcessCase $case, ProcessStep $step, ?int $subjectId): Collection
    {
        $configured = app(Settings::class)->get(self::StandInSetting);

        $standIn = $this->whichOfThemCanAct(
            $configured === null ? [] : [(int) $configured],
            $subjectId,
        );

        $this->warnOnce($case, $step, $standIn->first());

        return $standIn;
    }

    /**
     * Put one line on the case's own record saying this step has nobody holding it, and
     * put it there once.
     *
     * Once, because resolution runs every time a queue is read: a client who has left a
     * role vacant for a fortnight would otherwise have thousands of identical lines in the
     * one record this product asks a tribunal to read. The check costs a read of this
     * case's warnings, and it is only ever paid on the path where something is already
     * wrong — a step whose people were found never comes here at all.
     */
    private function warnOnce(ProcessCase $case, ProcessStep $step, ?User $standIn): void
    {
        $alreadySaid = CaseEvent::query()
            ->where('case_id', $case->getKey())
            ->where('type', self::NobodyHoldsItEvent)
            ->pluck('payload')
            ->contains(fn (mixed $payload) => (((array) $payload)['sequence'] ?? null) === $step->sequence);

        if ($alreadySaid) {
            return;
        }

        CaseEvent::create([
            'case_id' => $case->getKey(),
            'actor_id' => null,
            'type' => self::NobodyHoldsItEvent,
            'payload' => [
                'step' => $step->name,
                'sequence' => $step->sequence,
                'looked_for' => (array) $step->assignee_rule,
                'held_by_the_stand_in' => $standIn !== null,
                'stand_in_id' => $standIn?->getKey(),
            ],
        ]);
    }
}
