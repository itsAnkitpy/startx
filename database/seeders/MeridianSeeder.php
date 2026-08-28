<?php

namespace Database\Seeders;

use App\Authorization\StarterRoles;
use App\Models\Designation;
use App\Models\EmploymentRecord;
use App\Models\FormDefinition;
use App\Models\FormField;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
use App\Process\StepLink;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Meridian Logistics, with enough of a real company in it to see the engine working.
 *
 * A demo company rather than test fixtures. Everybody signs in, so the queue screen can
 * be looked at as each of them in turn and the answers compared — which is the only way
 * to check "whose turn is it" by hand rather than by reading a test name.
 *
 * The shape is deliberate. Two branches under one business line means a role held over
 * one branch can be seen not to reach the other. Two people sharing the HR head role over
 * Shimla means a shared queue can be seen to disappear from one person's list when the
 * other picks it up. And every open case's first step belongs to somebody different, so
 * no single sign-in shows everything.
 *
 * Everybody's password is the same and it is written in {@see self::Password}. This is a
 * seeder for a development machine and is never run anywhere else.
 */
class MeridianSeeder extends Seeder
{
    /** One password for every demo account, development machines only. */
    public const Password = 'startx-demo';

    /** The client company's own address is this, plus the domain in the environment. */
    public const Slug = 'meridian';

    public function run(): void
    {
        $this->platformSuperadmin();

        $meridian = Tenant::query()->firstOrCreate(
            ['slug' => self::Slug],
            ['name' => 'Meridian Logistics'],
        );

        TenantContext::run($meridian, function (): void {
            $units = $this->structure();
            $people = $this->people($units, $this->offices());

            $this->rolesTheyHold($people, $units);

            // Nobody holds HR head over Pune, so Rohit's clearance has no one to fall to
            // unless the client has named a stand-in. Naming Chandni means the vacant
            // path can be watched landing somewhere with a warning on the case, rather
            // than being taken on trust from a test name.
            app(Settings::class)->set(AssigneeResolver::StandInSetting, (int) $people['chandni']->getKey());

            $exit = $this->exitProcess();

            $this->casesAlreadyRunning($exit, $people);
            $this->theExitBuiltWithTheMistake($people);
            $this->hiringRequestsWaitingOnRakesh($this->hiringRequestProcess(), $people, $units);
        });
    }

    /**
     * SummerHill's own login, which is not a client company account and lives in its own
     * table. Recreated here so that rebuilding the database from the migrations does not
     * lock the machine's owner out of the platform side.
     */
    private function platformSuperadmin(): void
    {
        DB::table('platform_users')->updateOrInsert(
            ['email' => 'ankit@summerhill.test'],
            [
                'name' => 'Ankit Sharma',
                'password' => Hash::make(self::Password),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * One company, one business line, two branches. Two is the smallest number that shows
     * a role held over one branch not reaching the other.
     *
     * @return array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}
     */
    private function structure(): array
    {
        $company = OrgUnit::factory()->ofType('company')->create(['name' => 'Meridian Logistics']);
        $north = OrgUnit::factory()->under($company, 'business_line')->create(['name' => 'North Logistics']);

        return [
            'company' => $company,
            'north' => $north,
            'shimla' => OrgUnit::factory()->under($north, 'branch')->create(['name' => 'Shimla branch']),
            'pune' => OrgUnit::factory()->under($north, 'branch')->create(['name' => 'Pune branch']),
        ];
    }

    /**
     * Where people sit. A legal deadline is counted in working days against the office on
     * the person's own job row, so an exit cannot be opened for somebody who has none —
     * every demo person needs one.
     *
     * @return array{shimla: Office, pune: Office}
     */
    private function offices(): array
    {
        return [
            'shimla' => Office::factory()->named('Shimla office')->in('IN', 'IN-HP')->create(),
            'pune' => Office::factory()->named('Pune office')->in('IN', 'IN-MH')->create(),
        ];
    }

    /**
     * @param  array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}  $units
     * @param  array{shimla: Office, pune: Office}  $offices
     * @return array<string, User>
     */
    private function people(array $units, array $offices): array
    {
        // The client's own list of designations, because a job row points at an entry on
        // it rather than carrying typed words — and because the panel above every step's
        // form names the designation the person had when the case opened, which is blank
        // for anybody whose row points at nothing.
        $head = Designation::factory()->named('Regional Head')->create();
        $manager = Designation::factory()->named('Branch Manager')->create();
        $officer = Designation::factory()->named('Operations Officer')->create();

        $chandni = $this->person('Chandni Verma', $units['north'], $offices['pune'], $head);
        $rakesh = $this->person('Rakesh Menon', $units['shimla'], $offices['shimla'], $manager, $chandni);
        $priya = $this->person('Priya Nair', $units['shimla'], $offices['shimla'], $manager, $chandni);

        return [
            'chandni' => $chandni,
            'rakesh' => $rakesh,
            'priya' => $priya,
            'deepak' => $this->person('Deepak Iyer', $units['shimla'], $offices['shimla'], $officer, $rakesh),
            'anjali' => $this->person('Anjali Rao', $units['shimla'], $offices['shimla'], $officer, $rakesh),
            'rohit' => $this->person('Rohit Menon', $units['pune'], $offices['pune'], $officer, $chandni),
        ];
    }

    /** Somebody with an address they can sign in with and a dated job row. */
    private function person(string $name, OrgUnit $unit, Office $office, Designation $designation, ?User $manager = null): User
    {
        [$first] = explode(' ', $name, 2);

        $person = User::factory()
            ->named($name)
            ->create([
                'work_email' => strtolower($first).'@meridian.test',

                // The address that outlives the account, and the one a link for somebody
                // with no login is sent to. A leaver whose sign-in has been switched off
                // still has to confirm their own handover.
                'personal_email' => strtolower($first).'@personal.example',
                'password' => self::Password,
            ]);

        $row = EmploymentRecord::factory()->forPerson($person)->in($unit)->basedAt($office)
            ->designated($designation);

        $manager === null ? $row->create() : $row->reportingTo($manager)->create();

        return $person;
    }

    /**
     * Who holds what. Chandni and Priya are administrators because a client company keeps
     * at least two, and Rakesh and Priya share HR head over Shimla so that a queue with
     * two people in it can be watched changing hands.
     *
     * @param  array<string, User>  $people
     * @param  array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}  $units
     */
    private function rolesTheyHold(array $people, array $units): void
    {
        // Each demo role carries the actions the same role really carries on a client
        // company created through the platform, rather than being a name with nothing
        // behind it. Without them nobody in the demo may see a person's record, and the
        // screens that ask before they open would be shut to everybody.
        $starter = StarterRoles::definitions();

        $hrHead = Role::factory()->keyed('hr_head', 'HR head')
            ->withPermissions($starter['hr_head']['permissions'])->create();
        $administrator = Role::factory()->keyed(Role::AdministratorKey, 'Administrator')
            ->withPermissions($starter[Role::AdministratorKey]['permissions'])->create();
        $financeHead = Role::factory()->keyed('finance_head', 'Finance head')
            ->withPermissions($starter['finance_approver']['permissions'])->create();

        // Where every step of the exit goes when it runs past its own deadline. Held over
        // the whole company by one person, so a late Shimla clearance can be watched
        // appearing in her list beside the branch's own people rather than instead of them.
        $hrDirector = Role::factory()->keyed('hr_director', 'HR director')
            ->withPermissions($starter['hr_head']['permissions'])->create();

        $grant = function (Role $role, User $person, ?OrgUnit $unit) use (&$grant): void {
            $role->assignments()->create([
                'user_id' => $person->getKey(),
                'org_unit_id' => $unit?->getKey(),
                'includes_descendants' => false,
            ]);
        };

        // The two roles the hiring chain names. Neither is one a client starts with, which
        // is the point of them being here: a client adds the roles their own approvals
        // need, and nothing in our code knows either name. They carry no actions at all —
        // approving a step is not a granted action, it is whose job the step is — so what
        // these two demonstrate is exactly that separation.
        $lineOfBusiness = Role::factory()->keyed('lob_head', 'Line-of-business head')->create();
        $director = Role::factory()->keyed('director', 'Director')->create();

        $grant($hrHead, $people['rakesh'], $units['shimla']);
        $grant($hrHead, $people['priya'], $units['shimla']);
        $grant($financeHead, $people['chandni'], null);
        $grant($hrDirector, $people['chandni'], null);
        $grant($administrator, $people['chandni'], null);
        $grant($administrator, $people['priya'], null);

        // Rakesh holds line-of-business head over the Shimla branch, so a request naming
        // Shimla is his — and would climb to whoever holds that same role over North
        // Logistics if Shimla had nobody in it. Chandni holds director over the whole
        // company, so the expensive ones reach her whichever branch they name.
        $grant($lineOfBusiness, $people['rakesh'], $units['shimla']);
        $grant($director, $people['chandni'], null);
    }

    /**
     * A four-step exit: HR clears it, then finance, then the leaver's own manager signs it
     * off, and last the leaver themselves confirms the handover. Four groups rather than
     * one so that a later step can be watched staying out of everybody's list until the
     * step in front of it closes.
     *
     * **Every step an employee answers says where it goes when it runs late,** which is
     * the HR director over the whole company. It is on all three even though no client
     * asked for it: a process without it is an email chain with extra steps, and the
     * escalation is only visible at all on a step that has actually gone past its target.
     *
     * The last step is answered by somebody with no account, at their personal address,
     * because by then their sign-in is gone. It says nothing about running late on
     * purpose — the only permission on such a step is the link sent to that address, so
     * widening it to an employee would name people who would then be refused.
     */
    private function exitProcess(): ProcessTemplate
    {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        $lateItGoesTo = ['kind' => 'role_global', 'role' => 'hr_director'];

        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance')
            ->asking($this->hrClearanceForm())
            ->heldByTheRole('hr_head')->offering('approved', 'rejected')->dueIn(48)
            ->escalatingTo($lateItGoesTo)->create();

        ProcessStep::factory()->of($exit)->at(2, 2)->named('Finance clearance')
            ->asking($this->financeClearanceForm())
            ->heldByTheRoleAnywhere('finance_head')->offering('approved', 'rejected')->dueIn(48)
            ->escalatingTo($lateItGoesTo)->create();

        ProcessStep::factory()->of($exit)->at(3, 3)->named('Manager sign-off')
            ->offering('approved')->dueIn(24)->escalatingTo($lateItGoesTo)
            ->state(['assignee_rule' => ['kind' => 'reporting_manager']])->create();

        ProcessStep::factory()->of($exit)->at(4, 4)->named('Leaver confirms the handover')
            ->asking($this->handoverConfirmationForm())
            ->external()->offering('approved', 'rejected')->dueIn(72)->create();

        $exit->publish();

        return $exit;
    }

    /**
     * What HR asks when it clears an exit.
     *
     * Drawn from the old tool's own HR clearance screen — the ID card, what is being
     * recovered and why — but as rows in two tables rather than as columns on the exit
     * itself, which is the whole difference this module exists to make. Vertex Foods will
     * ask something different on the same step and nobody will write a migration.
     *
     * The photograph of the returned card is the demo's one attached document, and it is
     * asked only once somebody says the card came back — which is the whole point of
     * asking for it, and puts the two things this module has built so far on one card.
     */
    private function hrClearanceForm(): FormDefinition
    {
        $form = FormDefinition::factory()->named('hr_clearance', 'HR clearance')->create();

        FormField::factory()->on($form)->at(1)->required()
            ->asking('id_card_returned', 'ID card returned', FormField::Boolean)->create();

        FormField::factory()->on($form)->at(2)
            ->asking('id_card_photo', 'Photo or scan of the returned card', FormField::File)
            ->askedWhen('id_card_returned', '=', true)->create();

        FormField::factory()->on($form)->at(3)
            ->asking('notice_shortfall_days', 'Notice period short by (days)', FormField::Number)
            ->limitedBy(['min' => 0, 'max' => 180])->create();

        FormField::factory()->on($form)->at(4)
            ->asking('remarks', 'Anything HR wants on the record', FormField::Textarea)->create();

        $form->publish();

        return $form;
    }

    /**
     * What finance asks. Chandni holds this step for the whole company, so this is the
     * form on the card she opens — the imprest card back, what is owed each way, and why.
     *
     * Both money questions are `money` rather than `number` on purpose: module 08 turns a
     * money question on a clearance step into a line of the settlement statement, and a
     * plain number cannot become one because nothing says whether it is rupees or laptops.
     *
     * Two of the five are asked only in some cases, which is what the whole hiding rule is
     * for and is why they are on the demo. Nobody is asked how much to recover until they
     * say the imprest card did not come back, and nobody is asked what the recovery is for
     * until there is a figure to explain. Chandni holds this step for the company, so this
     * is the card the behaviour can actually be seen on.
     */
    private function financeClearanceForm(): FormDefinition
    {
        $form = FormDefinition::factory()->named('finance_clearance', 'Finance clearance')->create();

        FormField::factory()->on($form)->at(1)->required()
            ->asking('imprest_card_returned', 'Imprest card returned', FormField::Boolean)->create();

        FormField::factory()->on($form)->at(2)
            ->asking('recover_from_them', 'Amount to recover from them', FormField::Money)
            ->askedWhen('imprest_card_returned', '=', false)->create();

        FormField::factory()->on($form)->at(3)
            ->asking('recovery_reason', 'What the recovery is for', FormField::Select)
            ->askedWhen('recover_from_them', 'is_set')
            ->choosing([
                'advance' => 'Salary advance',
                'imprest' => 'Imprest not settled',
                'asset' => 'Asset not returned',
                'other' => 'Something else',
            ])->create();

        FormField::factory()->on($form)->at(4)
            ->asking('pay_to_them', 'Amount payable to them', FormField::Money)->create();

        FormField::factory()->on($form)->at(5)
            ->asking('remarks', 'Anything finance wants on the record', FormField::Textarea)->create();

        $form->publish();

        return $form;
    }

    /**
     * The one question the leaver is asked through their link.
     *
     * A form of its own rather than a box the link page invented, because an answer that
     * no step asked for is refused everywhere else in the product and this must not be
     * the exception. The link page still draws one fixed box; giving it the step's real
     * form is module 10's screen and is written down there.
     */
    private function handoverConfirmationForm(): FormDefinition
    {
        $form = FormDefinition::factory()->named('handover_note', 'Handover confirmation')->create();

        FormField::factory()->on($form)->at(1)
            ->asking('note', 'Anything you want on the record', FormField::Textarea)
            ->limitedBy(['max_length' => 2000])->create();

        $form->publish();

        return $form;
    }

    /**
     * Three exits already running, each one's first step belonging to somebody different
     * so that no single sign-in shows the whole picture.
     *
     * Anjali's is opened far enough back that its first step has already blown its
     * deadline, which is what puts the overdue marking on the queue screen where it can
     * be seen rather than taken on trust.
     *
     * @param  array<string, User>  $people
     */
    private function casesAlreadyRunning(ProcessTemplate $exit, array $people): void
    {
        $engine = new CaseEngine;

        $anjalis = $engine->open($exit, $people['anjali'], $people['chandni']);

        // Backdated after the fact rather than opened in the past, because the engine
        // stamps the moment a case opens and a step's own clock counts from it. Five days
        // against a two-day target is what puts a real overdue marker on the queue screen
        // instead of asking anybody to believe one would appear.
        $anjalis->forceFill(['opened_at' => now()->subDays(5)])->save();
        $engine->open($exit, $people['deepak'], $people['chandni']);
        $engine->open($exit, $people['rohit'], $people['chandni']);

        $this->rakeshsExitWaitingOnHisOwnAnswer($engine, $exit, $people);
    }

    /**
     * A fourth exit, Rakesh's, already cleared by everybody who works here and waiting on
     * the one person who does not: Rakesh himself, at his personal address, through a link.
     *
     * Driven forward by actually deciding the three steps as the people who hold them,
     * rather than by writing rows — so the state it leaves behind is a state the engine
     * can produce, and the link is issued exactly the way module 06's scheduled pass will
     * issue it when a step opens.
     *
     * It appears in nobody's queue, which is the point of it: a step answered by somebody
     * with no account has no resolved set at all, so the whole company can see the exit is
     * waiting and not one of them can answer it.
     *
     * @param  array<string, User>  $people
     */
    private function rakeshsExitWaitingOnHisOwnAnswer(CaseEngine $engine, ProcessTemplate $exit, array $people): void
    {
        $rakeshs = $engine->open($exit, $people['rakesh'], $people['chandni']);

        // Priya, because Rakesh holds HR head over Shimla himself and can never clear his
        // own exit. Then finance, then his own manager.
        //
        // Each clearance is answered, not merely approved. Until the engine applied a
        // form's rules these three went through with every box empty, which is a demo
        // showing two clearances that were never actually given — and the manager's
        // sign-off asks nothing at all, so it still passes nothing.
        $engine->decide($rakeshs, 1, 'approved', $people['priya'], ['id_card_returned' => true]);
        $engine->decide($rakeshs, 2, 'approved', $people['chandni'], ['imprest_card_returned' => true]);
        $engine->decide($rakeshs, 3, 'approved', $people['chandni']);

        $address = (new StepLink)->issue($rakeshs, 4);

        $this->command?->info('Rakesh has to confirm his own handover, and has no login. His link:');
        $this->command?->line($address);
    }

    /**
     * The hiring request: raise it, the branch approves it, and the director approves the
     * expensive ones as well.
     *
     * The whole of it is rows. Nothing in PHP knows what a hiring request is, which is the
     * claim this process exists to make — the same three tables that carry Meridian's exit
     * and Vertex's seven clearances carry this too.
     *
     * **Both approvals are scoped from the department the request itself names.** A case
     * about a vacancy has no job row for the engine to read a department from, so without
     * that the approvals would resolve to nobody and the request could never be approved.
     * The alternative was sending them to whoever holds the role anywhere in the company,
     * which in a client with three business lines puts one request in three inboxes.
     *
     * **The director's step opens on a figure the client can change**, compared against
     * the salary threshold in their own settings rather than a number written here. The
     * case freezes that threshold when it opens, so changing it moves the next request and
     * leaves the ones in flight where they are.
     *
     * **Send-back is deliberately not offered.** The queue screen draws a button for every
     * outcome a step allows and then decides with no reason and no target, so offering it
     * here would put a button on screen that throws. It arrives with the inputs it needs.
     */
    private function hiringRequestProcess(): ProcessTemplate
    {
        $hiring = ProcessTemplate::factory()->named('hiring_request', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Raise request')
            ->asking($this->hiringRequestForm())
            ->heldBy('anjali@meridian.test')->offering('approved')->create();

        ProcessStep::factory()->of($hiring)->at(2, 2)->named('Line-of-business approval')
            ->heldByTheRole('lob_head', 'department')
            ->offering('approved', 'rejected')->dueIn(48)->create();

        ProcessStep::factory()->of($hiring)->at(3, 3)->named('Director approval')
            ->heldByTheRole('director', 'department')
            ->offering('approved', 'rejected')->dueIn(48)
            ->happensWhen([[
                'source' => 'payload', 'field' => 'annual_ctc',
                'operator' => '>', 'setting' => 'hiring_director_threshold',
            ]])->create();

        $hiring->publish();

        return $hiring;
    }

    /**
     * The eight questions a hiring request asks.
     *
     * Two of them do more than record something. The department decides who both
     * approvals go to, and the salary decides whether the director is asked at all — so
     * the two questions that drive the whole chain are questions on a form a client owns,
     * not settings in our code.
     *
     * Whether the role replaces somebody who has left is here because every comparable
     * product asks it and this form did not. Greenhouse re-runs a job's approval when it
     * changes, which is its own answer to how much an approver's decision rests on it.
     */
    private function hiringRequestForm(): FormDefinition
    {
        $form = FormDefinition::factory()->named('hiring_request', 'Hiring request')->create();

        FormField::factory()->on($form)->at(1)->required()
            ->asking('department', 'Which part of the company', FormField::OrgUnitPicker)->create();

        FormField::factory()->on($form)->at(2)->required()
            ->asking('designation', 'Designation', FormField::DesignationPicker)->create();

        FormField::factory()->on($form)->at(3)->required()
            ->asking('replaces_a_leaver', 'Replacement or new headcount', FormField::Select)
            ->choosing([
                'replacement' => 'Replacing somebody who has left',
                'new_headcount' => 'New headcount',
            ])->create();

        FormField::factory()->on($form)->at(4)->required()
            ->asking('positions', 'How many positions', FormField::Number)
            ->limitedBy(['min' => 1, 'max' => 50])->create();

        FormField::factory()->on($form)->at(5)->required()
            ->asking('annual_ctc', 'Annual CTC offered', FormField::Money)->create();

        FormField::factory()->on($form)->at(6)->required()
            ->asking('employment_type', 'Employment type', FormField::Select)
            ->choosing([
                'permanent' => 'Permanent',
                'contract' => 'Fixed-term contract',
                'intern' => 'Intern',
            ])->create();

        FormField::factory()->on($form)->at(7)
            ->asking('target_start_date', 'Wanted by', FormField::Date)->create();

        FormField::factory()->on($form)->at(8)->required()
            ->asking('justification', 'Why the role is needed', FormField::Textarea)
            ->limitedBy(['max_length' => 2000])->create();

        $form->publish();

        return $form;
    }

    /**
     * Two hiring requests already raised and both waiting on Rakesh, one under the
     * client's salary threshold and one over it.
     *
     * Two rather than one so that both branches of the chain can be clicked straight
     * away: approving the cheaper one finishes it there and then, and approving the
     * expensive one hands it to Chandni instead. One request would show only whichever
     * branch it happened to take.
     *
     * Anjali raises both. She holds no role and has no queue of her own, which is the
     * point — anybody may ask for a hire, and the approvals are somebody else's.
     *
     * @param  array<string, User>  $people
     * @param  array{company: OrgUnit, north: OrgUnit, shimla: OrgUnit, pune: OrgUnit}  $units
     */
    private function hiringRequestsWaitingOnRakesh(ProcessTemplate $hiring, array $people, array $units): void
    {
        $engine = new CaseEngine;
        $wanted = now()->addMonths(2)->format('Y-m-d');

        $raise = function (array $answers) use ($engine, $hiring, $people): void {
            $request = $engine->open($hiring, by: $people['anjali']);

            $engine->decide($request, 1, 'approved', $people['anjali'], $answers);
        };

        $raise([
            'department' => $units['shimla']->getKey(),
            'designation' => $this->designationCalled('Operations Officer'),
            'replaces_a_leaver' => 'replacement',
            'positions' => 1,
            'annual_ctc' => 900000,
            'employment_type' => 'permanent',
            'target_start_date' => $wanted,
            'justification' => 'Replacing Deepak Iyer, whose exit is already running.',
        ]);

        $raise([
            'department' => $units['shimla']->getKey(),
            'designation' => $this->designationCalled('Branch Manager'),
            'replaces_a_leaver' => 'new_headcount',
            'positions' => 1,
            'annual_ctc' => 2400000,
            'employment_type' => 'permanent',
            'target_start_date' => $wanted,
            'justification' => 'Second manager for the Shimla branch as the depot volumes grow.',
        ]);
    }

    /** One of the client's own designations, by the words on it. */
    private function designationCalled(string $name): int
    {
        return (int) Designation::query()->where('name', $name)->value('id');
    }

    /**
     * A second exit process built with the mistake the check at publishing now refuses,
     * and one case running on it.
     *
     * It is here because the failure that check prevents cannot be seen anywhere else.
     * The manager's sign-off waits on `amount_recovered`, and what finance is actually
     * asked is `recover_from_them` — one rename apart, which is how this happens. Nothing
     * errors: the engine looks for an answer that was never collected, finds none, decides
     * the step is not wanted, and the exit finishes with the manager never having been
     * asked. Every screen looks exactly as it does when the sign-off was given properly.
     *
     * Priya is the leaver and her manager is Chandni, who also holds finance. So one
     * person can clear the finance step and watch the exit end without the sign-off that
     * was hers to give.
     *
     * Version 2 is left as an unfinished draft carrying the same mistake, so the refusal
     * can be read as well as the failure it prevents:
     * `php artisan process:publish exit_with_the_mistake --tenant=meridian`.
     *
     * @param  array<string, User>  $people
     */
    private function theExitBuiltWithTheMistake(array $people): void
    {
        // The form the real exit already uses, rather than a second copy of it — making
        // another version of the same form would archive the one Meridian's live exit
        // points at.
        $finance = FormDefinition::query()
            ->where('key', 'finance_clearance')
            ->where('status', FormDefinition::Published)
            ->sole();

        $broken = ProcessTemplate::factory()
            ->named('exit_with_the_mistake', 'Exit (the mistake this check catches)')
            ->about('employee')->create();

        ProcessStep::factory()->of($broken)->at(1, 1)->named('Finance clearance')
            ->asking($finance)
            ->heldByTheRoleAnywhere('finance_head')->offering('approved', 'rejected')->dueIn(48)->create();

        ProcessStep::factory()->of($broken)->at(2, 2)->named('Manager sign-off')
            ->offering('approved')->dueIn(24)
            ->state([
                'assignee_rule' => ['kind' => 'reporting_manager'],
                'open_conditions' => [[[
                    'source' => 'payload', 'field' => 'amount_recovered', 'operator' => '>', 'value' => 0,
                ]]],
            ])->create();

        // Made live without going through the check, because the check is what this exists
        // to demonstrate and it refuses exactly this. It is also the honest state of any
        // client who published a process before the check was written.
        $broken->forceFill(['status' => ProcessTemplate::Published])->save();

        (new CaseEngine)->open($broken, $people['priya'], $people['chandni']);

        $broken->draftNextVersion();
    }
}
