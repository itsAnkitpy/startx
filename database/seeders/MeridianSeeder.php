<?php

namespace Database\Seeders;

use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\OrgUnit;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Process\AssigneeResolver;
use App\Process\CaseEngine;
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
        $grant($administrator, $people['chandni'], null);
        $grant($administrator, $people['priya'], null);
    }

    /**
     * A three-step exit: HR clears it, then finance, then the leaver's own manager signs
     * it off. Three groups rather than one so that a later step can be watched staying
     * out of everybody's list until the step in front of it closes.
     */
    private function exitProcess(): ProcessTemplate
    {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('HR clearance')
            ->heldByTheRole('hr_head')->offering('approved', 'rejected')->dueIn(48)->create();

        ProcessStep::factory()->of($exit)->at(2, 2)->named('Finance clearance')
            ->heldByTheRoleAnywhere('finance_head')->offering('approved', 'rejected')->dueIn(48)->create();

        ProcessStep::factory()->of($exit)->at(3, 3)->named('Manager sign-off')
            ->offering('approved')->dueIn(24)
            ->state(['assignee_rule' => ['kind' => 'reporting_manager']])->create();

        $exit->publish();

        return $exit;
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
    }
}
