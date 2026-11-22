<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Process\AssigneeResolver;
use App\Settings\Settings;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SuccessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Rakesh has left for good and Chandni takes over.
 *
 * A cover is somebody away for a fortnight: it expires, it moves a queue and nothing
 * else, and Rakesh comes back to his own work. This is the other thing entirely. It
 * never ends and it moves everything in one go — the approvals Rakesh had opened or put
 * on hold, the roles he held, the people who reported to him, and the steps nobody holds
 * where he was the one they fell to.
 *
 * **It is not a very long cover, and the two are kept apart on purpose.** Workday draws
 * the same line in the same words: a reassignment moves ownership of the work, a
 * delegation does not. Letting a departure be recorded as an open-ended cover would
 * leave the org chart quietly rotting around somebody who has gone, because a cover
 * moves no reporting line and no role.
 *
 * **Nothing is repaired afterwards and nothing is scheduled.** The steps nobody had
 * picked up need no moving at all: whose job a step is, is worked out every time
 * somebody asks, so once the roles have moved those steps are already Chandni's. Only
 * the rows Rakesh had actually claimed have to be handed over, because a claimed step
 * has a row with his name on it. Oracle Fusion ships a nightly job to repair exactly
 * what it stored, and its own documentation says the job must run at least daily; that
 * bill is what resolving on read does not pay.
 *
 * **The org chart is added to, never rewritten.** Each of Rakesh's direct reports gets
 * a fresh dated job row saying they report to Chandni from the handover date, and their
 * previous row keeps its end date and stays readable. Repointing a column instead would
 * destroy the fact that thirty-four people used to report to Rakesh in the very act of
 * recording that they now report to Chandni — and that fact is what a case reviewed a
 * year later is read against.
 */
#[Fillable(['from_user_id', 'to_user_id', 'case_id', 'effective_at'])]
class Succession extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SuccessionFactory> */
    use HasFactory;

    /** What a transferred step's own case calls the handover in its history. */
    public const StepTransferredEvent = 'step_transferred';

    /** What the exit itself calls the handover in its own history. */
    public const HandoverSettledEvent = 'handover_settled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_at' => 'date',
        ];
    }

    /** The person who left. */
    public function leaver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** Whoever took the work on. */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /** The exit this was settled inside. */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcessCase::class, 'case_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * What the successor would inherit, counted before anybody confirms anything.
     *
     * *"Chandni will inherit: 12 pending approvals, 34 direct reports, HR head — Pune
     * branch."* Reassigning inside an exit screen while quietly rewriting the org chart
     * is not acceptable; doing it deliberately with the consequences on screen is
     * exactly the right moment, because it is the one moment the organisation knows it
     * is happening.
     *
     * **The steps counted are the ones the handover itself moves**: those the leaver picked
     * up or put on hold. Approvals nobody has started have no row to move and follow the
     * roles and the team, which the dialog lists. Counting those through the reader that
     * works out whose turn it is read 0 on the usual day to hand over, because by then the
     * leaver's sign-in is off and the reader leaves them out (Ankit's call, 22 November 2026).
     * A step they picked up covering for a colleague goes back to that colleague instead,
     * so it is counted apart.
     *
     * **The direct reports are the rows with no end date**, so the figure is who reports
     * to him today rather than everybody who ever did.
     *
     * @return array{steps_picked_up: int, steps_going_back: int, direct_reports: int, roles: list<string>, stand_in: bool}
     */
    public static function whatWouldMove(User $leaver): array
    {
        $steps = self::stepsStillTheirs($leaver)->get();
        $goingBack = $steps->filter(fn (CaseStep $step): bool => self::coveredFor($step, $leaver) !== null)->count();

        return [
            'steps_picked_up' => $steps->count() - $goingBack,
            'steps_going_back' => $goingBack,
            'direct_reports' => self::peopleReportingTo($leaver)->count(),
            'stand_in' => self::theyPickUpStepsNobodyHolds($leaver),
            'roles' => RoleAssignment::query()
                ->where('user_id', $leaver->getKey())
                ->with(['role', 'orgUnit'])
                ->get()
                ->map(fn (RoleAssignment $grant) => $grant->orgUnit === null
                    ? "{$grant->role->name} — the whole company"
                    : "{$grant->role->name} — {$grant->orgUnit->name}")
                ->values()
                ->all(),
        ];
    }

    /**
     * What a leaver still has that the handover would move, in one sentence, or null when
     * there is nothing — the question that keeps an exit from finishing (module 07 step 4).
     *
     * *"Rakesh Menon still has 2 roles and 2 people reporting to them…"* Asked by the
     * engine at the moment an exit would finish, and by the exit's page so HR sees it weeks
     * earlier. It holds the case, never the person: the last working day and both
     * deadlines are untouched.
     *
     * **Exactly what the handover moves, and nothing it does not.** An approval that
     * reaches Rakesh only through a cover, or by his name on a step, is not counted: no
     * handover moves those, so counting them would hold his exit for ever once the button
     * had gone, and once his account is off they fall to the next person anyway. The
     * approvals that reach him through a role or his team are counted by the role and the
     * team they come through.
     *
     * Counts rather than names, because anybody who can open the exit reads this, and the
     * roles screen asks for a grant before it shows who holds what. The handover's own
     * dialog, behind its permission, names them.
     *
     * After a handover, anything here reached them since — a role given by mistake, a new
     * hire set to report to them. Their work moves on once, so this says where to move it
     * by hand instead of pointing at a button that has gone.
     */
    public static function whatStillHasToMove(ProcessCase $exit): ?string
    {
        $leaver = $exit->subject;

        if ($exit->statutory_from === null || ! $leaver instanceof User) {
            return null;
        }

        // Not the starter Employee role: it grants nothing and a client may give it to
        // everybody, which would hold every exit for a handover with nothing to hand on.
        $roles = RoleAssignment::query()
            ->where('user_id', $leaver->getKey())
            ->whereRelation('role', 'key', '!=', Role::EmployeeKey)
            ->count();
        $reports = self::peopleReportingTo($leaver)->count();
        $steps = self::stepsStillTheirs($leaver)->count();

        $left = array_values(array_filter([
            $roles > 0 ? $roles.' '.($roles === 1 ? 'role' : 'roles') : null,
            $reports > 0 ? $reports.' '.($reports === 1 ? 'person' : 'people').' reporting to them' : null,
            $steps > 0 ? $steps.' '.($steps === 1 ? 'step' : 'steps').' they have picked up or put on hold' : null,
            self::theyPickUpStepsNobodyHolds($leaver) ? 'the steps nobody else holds' : null,
        ]));

        if ($left === []) {
            return null;
        }

        $what = collect($left)->join(', ', ' and ');
        $handedOver = self::query()->where('case_id', $exit->getKey())->first();

        if ($handedOver === null) {
            return "{$leaver->name} still has {$what}, and all of it has to be handed over before this exit can finish. "
                .'Press "Hand over their work" on this exit\'s page, or ask somebody who can.';
        }

        return "{$leaver->name}'s work was handed to {$handedOver->successor->name} on {$handedOver->effective_at->format('j F Y')}, "
            ."and since then {$what} reached them. That has to move on before this exit can finish: roles on the roles screen, "
            .'the people reporting to them on the people screen, and who picks up a step nobody holds on the settings screen. '
            .'A step they have picked up is theirs to answer before they go, and one they picked up while covering for somebody goes back to that person when their sign-in switches off.';
    }

    /**
     * Hand everything over, in one transaction.
     *
     * Every move or none of them. A handover that transferred the approvals and
     * then failed on the reporting lines would leave the client with work sitting on
     * somebody who has gone and an org chart that disagrees with it, and nothing on the
     * record saying which half happened.
     *
     * The leaver is read off the case rather than passed in, so the two can never
     * disagree about who is leaving.
     */
    public static function handOver(
        ProcessCase $case,
        User $successor,
        User $by,
        DateTimeInterface|string|null $on = null,
    ): self {
        $leaver = $case->subject;

        if ($leaver === null) {
            throw ProcessRefused::aHandoverNeedsSomebodyLeaving();
        }

        if ((int) $leaver->getKey() === (int) $successor->getKey()) {
            throw ProcessRefused::nobodySucceedsThemselves($leaver->name);
        }

        // The same rule the three acts outside a step already carry: the person the case
        // is about does not settle it, and nobody hands the work to themselves. Rakesh
        // confirming who inherits his own branch, or the successor confirming their own
        // inheritance, is the signature this product cannot afford to record. The plan
        // has the manager nominating and HR confirming, and both of those are somebody
        // else.
        //
        // ponytail: *which* other employees may confirm a handover is the same open
        // permission as cancelling a case or overriding a hold, and module 07's screens
        // own it. Until then any other account holder still reaches this.
        if ((int) $leaver->getKey() === (int) $by->getKey()) {
            throw ProcessRefused::theCaseIsAboutThem('Settling who takes the work on');
        }

        if ((int) $successor->getKey() === (int) $by->getKey()) {
            throw ProcessRefused::nobodyHandsTheWorkToThemselves($successor->name);
        }

        // Somebody's work only moves on once per exit. A second handover finds no claimed
        // steps, no roles and no direct reports left, so it would change nothing while
        // looking to whoever pressed confirm exactly like one that worked — which is
        // precisely what an HR person correcting a wrong successor would do. They are told
        // who holds the work now and sent to hand it on from there instead. A later exit
        // hands over again: Rakesh staying after his first was withdrawn was given his
        // work back by hand, and it has to move on when he really goes.
        $alreadyMoved = self::query()->where('case_id', $case->getKey())->first();

        if ($alreadyMoved !== null) {
            throw ProcessRefused::theWorkHasAlreadyBeenHandedOn(
                $leaver->name,
                $alreadyMoved->successor->name,
                $alreadyMoved->effective_at->toDateString(),
            );
        }

        // A successor who cannot sign in inherits the work into a hole: nothing resolves
        // to a dead account, so thirty-four reporting lines and a branch's approvals
        // would all fall to the client's stand-in with nobody having asked for that.
        if (! $successor->active) {
            throw ProcessRefused::theSuccessorCannotSignIn($successor->name);
        }

        $from = CarbonImmutable::parse($on ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($case, $leaver, $successor, $by, $from): self {
            $succession = new self([
                'from_user_id' => $leaver->getKey(),
                'to_user_id' => $successor->getKey(),
                'case_id' => $case->getKey(),
                'effective_at' => $from->toDateString(),
            ]);

            $succession->recorded_by = $by->getKey();
            $succession->save();

            $steps = self::moveTheStepsHeHadOpened($leaver, $successor, $case, $by);

            $moved = [
                'approvals' => $steps['to_the_successor'],
                'handed_back' => $steps['handed_back'],
                'roles' => self::moveTheRolesHeHeld($leaver, $successor),
                'reporting_lines' => self::moveThePeopleWhoReportedToHim($leaver, $successor, $case, $by, $from),
                'stand_in' => self::moveTheStandIn($leaver, $successor),
            ];

            // The exit's own history says the handover happened, alongside the line each
            // moved approval writes into the case it belongs to. Without it somebody
            // reading Rakesh's exit next year sees the clearances and the reporting
            // lines change everywhere else and nothing at all on the exit that caused
            // them — and the exit is where they would look first.
            CaseEvent::create([
                'case_id' => $case->getKey(),
                'actor_id' => $by->getKey(),
                'type' => self::HandoverSettledEvent,
                'payload' => [
                    'from' => ['id' => (int) $leaver->getKey(), 'name' => $leaver->name],
                    'to' => ['id' => (int) $successor->getKey(), 'name' => $successor->name],
                    'effective_at' => $from->toDateString(),
                    'moved' => $moved,
                ],
            ]);

            return $succession;
        });
    }

    /**
     * The steps Rakesh had already picked up or put on hold, handed to Chandni and written
     * into each step's own case as a transfer. A held step moves with its hold, so Chandni
     * ends it as finance would have.
     *
     * Into the case the step belongs to, not into the exit — a tribunal reading Anjali's
     * exit next year needs to see there that the clearance changed hands and why, and
     * would never think to look in somebody else's exit for it.
     *
     * Recorded as a transfer rather than as a rewrite. The row now says Chandni, and the
     * history beside it says the step was Rakesh's and moved on the day he left. That
     * single distinction is the whole difference between a handover and a forged
     * history.
     *
     * Only the steps he had opened. A step nobody has touched has no row to move, and
     * needs none: whose job it is, is worked out on every read, and once the roles below
     * have moved it is already the successor's.
     *
     * **One he picked up covering for a colleague goes back to that colleague**, as it does
     * when his sign-in switches off. Given to the successor, it sat on somebody who could
     * not answer it, never their role or their cover, while the colleague was told somebody
     * else had it (module 03's handover, found 22 November 2026).
     *
     * @return array{to_the_successor: int, handed_back: int}
     */
    private static function moveTheStepsHeHadOpened(
        User $leaver,
        User $successor,
        ProcessCase $because,
        User $by,
    ): array {
        $moved = ['to_the_successor' => 0, 'handed_back' => 0];

        foreach (self::stepsStillTheirs($leaver)->get() as $step) {
            $colleague = self::coveredFor($step, $leaver);

            self::moveOneStep($step, $leaver, $colleague ?? $successor, $by, $colleague === null
                ? "{$leaver->name} left the company"
                : "{$leaver->name} picked it up covering for {$colleague->name}, and left the company", $because);

            $moved[$colleague === null ? 'to_the_successor' : 'handed_back']++;
        }

        return $moved;
    }

    /**
     * The steps somebody picked up or put on hold while covering for a colleague, given
     * back to that colleague when their sign-in is switched off at the end of their last
     * working day (module 07 step 5).
     *
     * Rakesh covering Chandni's finance approvals picks up Deepak's finance clearance the
     * day before he goes. Without this nobody can answer it once he cannot sign in: it has
     * his name on it, so Chandni is refused, and it holds his own exit for ever. Who he
     * was covering for was written on the step when he picked it up, so nothing new is
     * stored. A hold goes back with its reason and amount, as the handover moves one.
     *
     * Only a cover. A step he picked up in his own right after the handover has nobody to
     * go back to, and stays on him with his exit's page saying so. Nor to a colleague who
     * cannot sign in either, which would only move the hole.
     *
     * @return int how many went back
     */
    public static function giveBackWhatTheyPickedUpCovering(User $leaver, ProcessCase $because): int
    {
        $given = 0;

        foreach (self::stepsStillTheirs($leaver)->get() as $step) {
            $colleague = self::coveredFor($step, $leaver);

            if ($colleague === null) {
                continue;
            }

            self::moveOneStep(
                $step,
                $leaver,
                $colleague,
                null,
                "{$leaver->name} picked it up covering for {$colleague->name}, and their sign-in was switched off at the end of their last working day",
                $because,
            );
            $given++;
        }

        return $given;
    }

    /**
     * The colleague somebody picked a step up covering for, read off the step as they
     * picked it up, or null where it was theirs in their own right or that colleague cannot
     * sign in either, which would only move the hole.
     */
    private static function coveredFor(CaseStep $step, User $leaver): ?User
    {
        $asTheyPickedItUp = collect($step->candidates_at_claim ?? [])
            ->first(fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === (int) $leaver->getKey());
        $coveredFor = $asTheyPickedItUp['covering_for']['id'] ?? null;

        return $coveredFor === null ? null : User::query()->whereKey($coveredFor)->where('active', true)->first();
    }

    /**
     * One step changing hands, written into its own case as a transfer so the history says
     * who had it, who has it now and why.
     */
    private static function moveOneStep(CaseStep $step, User $from, User $to, ?User $by, string $why, ProcessCase $settledIn): void
    {
        $step->update(['assignee_id' => $to->getKey()]);

        CaseEvent::create([
            'case_id' => $step->case_id,
            'actor_id' => $by?->getKey(),
            'type' => self::StepTransferredEvent,
            'payload' => [
                'sequence' => $step->sequence,
                'from' => ['id' => (int) $from->getKey(), 'name' => $from->name],
                'to' => ['id' => (int) $to->getKey(), 'name' => $to->name],
                'because' => $why,
                'settled_in_case' => (int) $settledIn->getKey(),
            ],
        ]);
    }

    /**
     * The steps on somebody that nobody else can answer while they are on them: picked up
     * and not yet answered, or put on hold.
     *
     * **A hold is included because it is still theirs.** Chandni holding finance on
     * Deepak's exit with 40,000 to recover owns that clearance until she ends it, and the
     * queue shows it to nobody else. Left behind, it would sit on her after she had gone,
     * waiting on nobody, and only an override could end it — which reads as HR overruling
     * finance rather than finance's successor deciding (module 07 step 4).
     *
     * **Only on a case still running.** A step left picked up on Deepak's exit when it was
     * withdrawn can never be answered, so counting it would hold Rakesh's exit for ever.
     *
     * @return Builder<CaseStep>
     */
    private static function stepsStillTheirs(User $person): Builder
    {
        return CaseStep::query()
            ->where('assignee_id', $person->getKey())
            ->where(fn (Builder $open) => $open->whereNull('outcome')->orWhere('outcome', 'held'))
            ->whereNull('superseded_at')
            ->whereHas('case', fn (Builder $running) => $running->whereNull('closed_at')->whereNull('cancelled_at'));
    }

    /**
     * Who reports to somebody today: the job rows with no end date.
     *
     * @return Builder<EmploymentRecord>
     */
    private static function peopleReportingTo(User $person): Builder
    {
        return EmploymentRecord::query()
            ->where('reports_to_id', $person->getKey())
            ->whereNull('effective_to');
    }

    /** Whether the company's setting for who picks up a step nobody holds names this person. */
    private static function theyPickUpStepsNobodyHolds(User $person): bool
    {
        return (int) app(Settings::class)->get(AssigneeResolver::StandInSetting) === (int) $person->getKey();
    }

    /**
     * The company's stand-in for steps nobody holds, moved to the successor when it names
     * the leaver, so the one person a vacant clearance falls to is not somebody who has
     * gone. Chandni is Meridian's: Rohit's HR clearance, with nobody holding HR head over
     * Pune, waits on her. Moved rather than left to be refused at the final check, because
     * the successor taking over her roles is the obvious one to take this too, and the
     * settings screen still changes it afterwards.
     */
    private static function moveTheStandIn(User $leaver, User $successor): bool
    {
        if (! self::theyPickUpStepsNobodyHolds($leaver)) {
            return false;
        }

        app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $successor->getKey());

        return true;
    }

    /**
     * The roles he held, moved with their scope untouched.
     *
     * "HR head, Pune branch" arrives as "HR head, Pune branch" and never as "HR head".
     * Widening a grant on the way through is the one mistake here a client would never
     * see and could never forgive — it would quietly make the successor the answer to
     * every question the role is asked anywhere in the company.
     *
     * Which is why the grant is moved rather than taken away and written again: the part
     * of the structure it covers, and whether it reaches down into everything below,
     * are columns on the row and go along with it untouched.
     *
     * **Where the successor already holds the same role over the same part of the
     * company, the leaver's grant is retired rather than moved.** Two people holding one
     * role over one branch is an ordinary shared queue, and promoting one of them when
     * the other leaves is the ordinary way a branch is handed on — but the database keeps
     * one grant per person per role per unit, so moving the row would collide with the
     * one the successor already has. The wider reach of the two is kept, because a grant
     * that reached down into everything below the branch must not quietly stop doing so
     * on the day it changes hands.
     */
    private static function moveTheRolesHeHeld(User $leaver, User $successor): int
    {
        $alreadyHeld = RoleAssignment::query()
            ->where('user_id', $successor->getKey())
            ->get()
            ->keyBy(fn (RoleAssignment $held) => "{$held->role_id}:{$held->org_unit_id}");

        $grants = RoleAssignment::query()->where('user_id', $leaver->getKey())->get();

        foreach ($grants as $grant) {
            $held = $alreadyHeld->get("{$grant->role_id}:{$grant->org_unit_id}");

            if ($held === null) {
                $grant->update(['user_id' => $successor->getKey()]);

                continue;
            }

            if ($grant->includes_descendants && ! $held->includes_descendants) {
                $held->update(['includes_descendants' => true]);
            }

            $grant->delete();
        }

        return $grants->count();
    }

    /**
     * The people who reported to him, moved by writing each of them a new dated job row.
     *
     * Their previous row is closed off the day before the handover and stays exactly as
     * it is. Nothing is overwritten, so the reporting line as it stood last month still
     * reads correctly, and each new row names the exit that caused it — which is what
     * links the org chart movement and the departure that produced it in both
     * directions.
     *
     * The order inside this loop is the same one a withdrawal uses and for the same
     * reason: the database allows one open row per person, so the old row has to be
     * closed before the new one can be inserted.
     *
     * **Where the successor is one of the leaver's own team — which is how a branch is
     * usually handed on — they take the leaver's place in the chart rather than reporting
     * to themselves.** Their new row names whoever the leaver reported to, so Rakesh's
     * manager becomes Deepak's, and a leaver at the top of the tree leaves their
     * successor at the top of it.
     */
    private static function moveThePeopleWhoReportedToHim(
        User $leaver,
        User $successor,
        ProcessCase $because,
        User $by,
        CarbonImmutable $from,
    ): int {
        $lastDayUnderHim = $from->subDay();

        $leaversOwnManagerId = EmploymentRecord::query()
            ->where('user_id', $leaver->getKey())
            ->whereNull('effective_to')
            ->value('reports_to_id');

        $reports = self::peopleReportingTo($leaver)->with('user')->get();

        foreach ($reports as $current) {
            // Somebody whose own job row began on or after the handover date has no
            // room in front of it for the day the old row would have to end on. Refused
            // by name rather than left to the database, so whoever is confirming is told
            // which person and which date to look at instead of being shown a constraint.
            if ($current->effective_from->greaterThan($lastDayUnderHim)) {
                throw ProcessRefused::theHandoverStartsTooEarly(
                    $current->user->name,
                    $current->effective_from->toDateString(),
                    $from->toDateString(),
                );
            }

            $moved = $current->replicate();

            $current->update(['effective_to' => $lastDayUnderHim->toDateString()]);

            $moved->reports_to_id = (int) $current->user_id === (int) $successor->getKey()
                ? $leaversOwnManagerId
                : $successor->getKey();
            $moved->effective_from = $from->toDateString();
            $moved->effective_to = null;
            $moved->change_reason = "{$leaver->name} left the company";
            $moved->caused_by_case_id = $because->getKey();
            $moved->recorded_by = $by->getKey();
            $moved->save();
        }

        return $reports->count();
    }
}
