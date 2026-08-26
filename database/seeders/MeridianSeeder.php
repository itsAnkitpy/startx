<?php

namespace Database\Seeders;

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
        $chandni = $this->person('Chandni Verma', $units['north'], $offices['pune']);
        $rakesh = $this->person('Rakesh Menon', $units['shimla'], $offices['shimla'], $chandni);
        $priya = $this->person('Priya Nair', $units['shimla'], $offices['shimla'], $chandni);

        return [
            'chandni' => $chandni,
            'rakesh' => $rakesh,
            'priya' => $priya,
            'deepak' => $this->person('Deepak Iyer', $units['shimla'], $offices['shimla'], $rakesh),
            'anjali' => $this->person('Anjali Rao', $units['shimla'], $offices['shimla'], $rakesh),
            'rohit' => $this->person('Rohit Menon', $units['pune'], $offices['pune'], $chandni),
        ];
    }

    /** Somebody with an address they can sign in with and a dated job row. */
    private function person(string $name, OrgUnit $unit, Office $office, ?User $manager = null): User
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

        $row = EmploymentRecord::factory()->forPerson($person)->in($unit)->basedAt($office);

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
        $hrHead = Role::factory()->keyed('hr_head', 'HR head')->create();
        $administrator = Role::factory()->keyed(Role::AdministratorKey, 'Administrator')->create();
        $financeHead = Role::factory()->keyed('finance_head', 'Finance head')->create();

        // Where every step of the exit goes when it runs past its own deadline. Held over
        // the whole company by one person, so a late Shimla clearance can be watched
        // appearing in her list beside the branch's own people rather than instead of them.
        $hrDirector = Role::factory()->keyed('hr_director', 'HR director')->create();

        $grant = function (Role $role, User $person, ?OrgUnit $unit) use (&$grant): void {
            $role->assignments()->create([
                'user_id' => $person->getKey(),
                'org_unit_id' => $unit?->getKey(),
                'includes_descendants' => false,
            ]);
        };

        $grant($hrHead, $people['rakesh'], $units['shimla']);
        $grant($hrHead, $people['priya'], $units['shimla']);
        $grant($financeHead, $people['chandni'], null);
        $grant($hrDirector, $people['chandni'], null);
        $grant($administrator, $people['chandni'], null);
        $grant($administrator, $people['priya'], null);
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
        $engine->decide($rakeshs, 1, 'approved', $people['priya']);
        $engine->decide($rakeshs, 2, 'approved', $people['chandni']);
        $engine->decide($rakeshs, 3, 'approved', $people['chandni']);

        $address = (new StepLink)->issue($rakeshs, 4);

        $this->command?->info('Rakesh has to confirm his own handover, and has no login. His link:');
        $this->command?->line($address);
    }
}
