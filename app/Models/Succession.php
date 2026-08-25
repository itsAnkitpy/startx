<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Process\AvailableSteps;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SuccessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Rakesh has left for good and Chandni takes over.
 *
 * A cover is somebody away for a fortnight: it expires, it moves a queue and nothing
 * else, and Rakesh comes back to his own work. This is the other thing entirely. It
 * never ends and it moves three things in one go — the approvals Rakesh had opened, the
 * roles he held, and the people who reported to him.
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
     * **The approvals are counted through the reader that works out whose turn it is,
     * not from the rows people have already opened.** Most of Rakesh's twelve are steps
     * he never touched, and a step nobody has touched has no row anywhere — so a count
     * taken from the rows would show a reassuringly small number and miss exactly the
     * work that has been sitting untouched, which is the work most likely to breach.
     *
     * **The direct reports are the rows with no end date**, so the figure is who reports
     * to him today rather than everybody who ever did.
     *
     * ponytail: whose job each waiting step is, is worked out one step at a time, so a
     * client with a great many open cases pays a handful of reads per open step for this
     * one screen. Left as it is because it is a once-per-exit answer a person is waiting
     * on, and because the alternative — storing who each step belongs to — is the thing
     * this whole module exists to avoid. Narrow the open cases first if it is ever felt.
     *
     * @return array{approvals_waiting: int, direct_reports: int, roles: list<string>}
     */
    public static function whatWouldMove(User $leaver): array
    {
        return [
            'approvals_waiting' => (new AvailableSteps)->waitingOn($leaver)->count(),
            'direct_reports' => EmploymentRecord::query()
                ->where('reports_to_id', $leaver->getKey())
                ->whereNull('effective_to')
                ->count(),
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
     * Hand everything over, in one transaction.
     *
     * All three moves or none of them. A handover that transferred the approvals and
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

        // Somebody's work only moves on once. A second handover finds no claimed steps,
        // no roles and no direct reports left, so it would change nothing while looking
        // to whoever pressed confirm exactly like one that worked — which is precisely
        // what an HR person correcting a wrong successor would do. They are told who
        // holds the work now and sent to hand it on from there instead.
        $alreadyMoved = self::query()->where('from_user_id', $leaver->getKey())->first();

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

            $moved = [
                'approvals' => self::moveTheStepsHeHadOpened($leaver, $successor, $case, $by),
                'roles' => self::moveTheRolesHeHeld($leaver, $successor),
                'reporting_lines' => self::moveThePeopleWhoReportedToHim($leaver, $successor, $case, $by, $from),
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
     * The steps Rakesh had already picked up, handed to Chandni and written into each
     * step's own case as a transfer.
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
     */
    private static function moveTheStepsHeHadOpened(
        User $leaver,
        User $successor,
        ProcessCase $because,
        User $by,
    ): int {
        $claimed = CaseStep::query()
            ->where('assignee_id', $leaver->getKey())
            ->whereNull('outcome')
            ->whereNull('superseded_at')
            ->get();

        foreach ($claimed as $step) {
            $step->update(['assignee_id' => $successor->getKey()]);

            CaseEvent::create([
                'case_id' => $step->case_id,
                'actor_id' => $by->getKey(),
                'type' => self::StepTransferredEvent,
                'payload' => [
                    'sequence' => $step->sequence,
                    'from' => ['id' => (int) $leaver->getKey(), 'name' => $leaver->name],
                    'to' => ['id' => (int) $successor->getKey(), 'name' => $successor->name],
                    'because' => "{$leaver->name} left the company",
                    'settled_in_case' => (int) $because->getKey(),
                ],
            ]);
        }

        return $claimed->count();
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

        $reports = EmploymentRecord::query()
            ->where('reports_to_id', $leaver->getKey())
            ->whereNull('effective_to')
            ->with('user')
            ->get();

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
