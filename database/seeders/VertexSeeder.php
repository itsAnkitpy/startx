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
use App\Process\CaseEngine;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Vertex Foods — a second client company whose exit asks for something else entirely.
 *
 * This is where the whole module is cashed in. Meridian's exit asks four questions on one
 * HR clearance; Vertex's asks fifty-one across seven departments, all clearing at once.
 * Not one migration separates them, no class knows the name of a single question, and the
 * code below builds all seven forms from the same six lines — the difference between them
 * is rows.
 *
 * **The seven are real.** They are the department clearances the old tool actually ran,
 * taken field for field from its own written process. That tool carried them as roughly
 * seventy-one approval, clearance, status and remark columns on one table, arrived at
 * through twenty-two add-column migrations — which is what happens when a client's form is
 * a schema. Three field names were its own internal products rather than anything a
 * customer would recognise, and those are named here for what they do; everything else is
 * as it was, including which questions stop a clearance when they are unanswered and which
 * only appear once an earlier answer calls for them.
 *
 * Everybody signs in with {@see MeridianSeeder::Password}, at first-name@vertex.test.
 */
class VertexSeeder extends Seeder
{
    /** The client company's own address is this, plus the domain in the environment. */
    public const Slug = 'vertex';

    /**
     * The seven department clearances, as data.
     *
     * A question is `[key, label, type]`, with `required` where the old tool stopped the
     * clearance without it, and `when` where the question only appears once an earlier
     * one on the same form has been answered a certain way.
     *
     * **Every key is unique across the whole process, and that is not tidiness.** The
     * engine pools every step's answers when it works out which steps a case still wants,
     * so two departments both answering `recovery_amount` would leave a condition naming
     * it reading whichever was answered last. The old tool prefixed its own columns by
     * department for the same reason, and that is kept.
     *
     * @var array<string, array{name: string, held_by: ?string, asks: list<array<string, mixed>>}>
     */
    private const Clearances = [
        'manager_noc' => [
            'name' => 'Reporting manager clearance',
            'held_by' => null,
            'asks' => [
                ['key' => 'handover_completed', 'label' => 'Handover completed', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'manager_articles_received', 'label' => 'Company articles received back', 'type' => FormField::Boolean],
                ['key' => 'manager_recovery_amount', 'label' => 'Amount to recover from them', 'type' => FormField::Money],
                ['key' => 'manager_recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Textarea, 'when' => ['manager_recovery_amount', 'is_set']],
                ['key' => 'manager_net_amount', 'label' => 'Net amount after this clearance', 'type' => FormField::Money],
                ['key' => 'manager_remarks', 'label' => 'Anything the manager wants on the record', 'type' => FormField::Textarea],
            ],
        ],
        'admin_noc' => [
            'name' => 'Admin clearance',
            'held_by' => 'admin_services',
            'asks' => [
                ['key' => 'assets_handed_back', 'label' => 'Assets handed back', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'assets_not_returned_reason', 'label' => 'Why the assets did not come back', 'type' => FormField::Textarea, 'when' => ['assets_handed_back', '=', false]],
                ['key' => 'admin_articles_received', 'label' => 'Company articles received back', 'type' => FormField::Boolean],
                ['key' => 'admin_recovery_amount', 'label' => 'Amount to recover from them', 'type' => FormField::Money],
                ['key' => 'admin_recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Textarea, 'when' => ['admin_recovery_amount', 'is_set']],
                ['key' => 'admin_remarks', 'label' => 'Anything admin wants on the record', 'type' => FormField::Textarea],
            ],
        ],
        'crm_noc' => [
            'name' => 'CRM clearance',
            'held_by' => 'crm_head',
            'asks' => [
                ['key' => 'crm_recovery_amount', 'label' => 'Amount to recover from them', 'type' => FormField::Money],
                ['key' => 'crm_recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Textarea, 'when' => ['crm_recovery_amount', 'is_set']],
                ['key' => 'crm_remarks', 'label' => 'Anything CRM wants on the record', 'type' => FormField::Textarea],
            ],
        ],
        'it_noc' => [
            'name' => 'IT clearance',
            'held_by' => 'it_support',
            'asks' => [
                ['key' => 'mailbox_switched_off', 'label' => 'Work mailbox switched off', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'mailbox_reason', 'label' => 'Why the mailbox is still open', 'type' => FormField::Textarea, 'when' => ['mailbox_switched_off', '=', false]],
                ['key' => 'intranet_access_removed', 'label' => 'Intranet access removed', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'intranet_reason', 'label' => 'Why the access is still there', 'type' => FormField::Textarea, 'when' => ['intranet_access_removed', '=', false]],
                ['key' => 'it_assets_handed_back', 'label' => 'IT assets handed back', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'it_assets_reason', 'label' => 'Why the IT assets did not come back', 'type' => FormField::Textarea, 'when' => ['it_assets_handed_back', '=', false]],
                ['key' => 'it_recovery_amount', 'label' => 'Amount to recover from them', 'type' => FormField::Money],
                ['key' => 'it_recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Textarea, 'when' => ['it_recovery_amount', 'is_set']],
                ['key' => 'it_clearance_sheet', 'label' => 'The signed IT clearance sheet', 'type' => FormField::File],
                ['key' => 'it_remarks', 'label' => 'Anything IT wants on the record', 'type' => FormField::Textarea],
            ],
        ],
        'booking_noc' => [
            'name' => 'Booking system clearance',
            'held_by' => 'booking_admin',
            'asks' => [
                ['key' => 'booking_account_switched_off', 'label' => 'Booking system account switched off', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'booking_account_reason', 'label' => 'Why the account is still open', 'type' => FormField::Textarea, 'when' => ['booking_account_switched_off', '=', false]],
                ['key' => 'booking_remarks', 'label' => 'Anything the booking desk wants on the record', 'type' => FormField::Textarea],
            ],
        ],
        'finance_noc' => [
            'name' => 'Finance clearance',
            'held_by' => 'finance_head',
            'asks' => [
                ['key' => 'imprest_card_recovered', 'label' => 'Imprest card recovered', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'imprest_card_reason', 'label' => 'Why the imprest card did not come back', 'type' => FormField::Textarea, 'when' => ['imprest_card_recovered', '=', false]],
                ['key' => 'finance_recovery_amount', 'label' => 'Amount to recover from them', 'type' => FormField::Money],
                ['key' => 'finance_recovery_reason', 'label' => 'What the recovery is for', 'type' => FormField::Textarea, 'when' => ['finance_recovery_amount', 'is_set']],
                ['key' => 'finance_payable_amount', 'label' => 'Amount payable to them', 'type' => FormField::Money],
                ['key' => 'finance_payable_reason', 'label' => 'What the payment is for', 'type' => FormField::Textarea, 'when' => ['finance_payable_amount', 'is_set']],
                ['key' => 'finance_net_amount', 'label' => 'Net amount after this clearance', 'type' => FormField::Money],
                ['key' => 'finance_remarks', 'label' => 'Anything finance wants on the record', 'type' => FormField::Textarea],
            ],
        ],
        'hr_noc' => [
            'name' => 'HR clearance',
            'held_by' => 'hr_head',
            'asks' => [
                ['key' => 'hr_id_card_returned', 'label' => 'ID card returned', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'hr_medical_card_returned', 'label' => 'Medical card returned', 'type' => FormField::Boolean],
                ['key' => 'hr_biometric_deactivated', 'label' => 'Biometric access switched off', 'type' => FormField::Boolean, 'required' => true],
                ['key' => 'notice_recovery_amount', 'label' => 'Notice period shortfall to recover', 'type' => FormField::Money],
                ['key' => 'notice_payable_amount', 'label' => 'Notice period payable to them', 'type' => FormField::Money],
                ['key' => 'encashment_recovery_amount', 'label' => 'Leave encashment to recover', 'type' => FormField::Money],
                ['key' => 'encashment_payable_amount', 'label' => 'Leave encashment payable to them', 'type' => FormField::Money],
                ['key' => 'telecom_recovery_amount', 'label' => 'Telephone and data to recover', 'type' => FormField::Money],
                ['key' => 'telecom_payable_amount', 'label' => 'Telephone and data payable to them', 'type' => FormField::Money],
                ['key' => 'hr_other_recovery_amount', 'label' => 'Anything else to recover', 'type' => FormField::Money],
                ['key' => 'hr_other_payable_amount', 'label' => 'Anything else payable to them', 'type' => FormField::Money],
                ['key' => 'hr_other_reason', 'label' => 'What the other recovery is for', 'type' => FormField::Textarea, 'when' => ['hr_other_recovery_amount', 'is_set']],
                ['key' => 'hr_remarks', 'label' => 'Anything HR wants on the record', 'type' => FormField::Textarea],
            ],
        ],
    ];

    public function run(): void
    {
        $vertex = Tenant::query()->firstOrCreate(
            ['slug' => self::Slug],
            ['name' => 'Vertex Foods'],
        );

        TenantContext::run($vertex, function (): void {
            $plant = $this->structure();
            $people = $this->people($plant, $this->office());

            $this->rolesTheyHold($people);

            // No stand-in is named here on purpose. A clearance whose role has no
            // holder stops where it is, and Meridian is where the stand-in path can be
            // watched instead.
            (new CaseEngine)->open(
                $this->exitProcess(),
                $people['neha'],
                $people['meera'],

                // A real legal clock, unlike Meridian's, so the deadline on the panel
                // above every one of these seven forms is a date rather than a blank.
                // Counted from the last working day on her own job row.
                statutoryFrom: $people['neha']->currentEmployment->last_working_day->toDateString(),
            );
        });
    }

    /**
     * Three levels rather than Meridian's three-with-two-branches, so the two demo
     * companies do not have the same shape. One plant is all seven clearances need.
     *
     * @return OrgUnit the plant everybody sits in
     */
    private function structure(): OrgUnit
    {
        $company = OrgUnit::factory()->ofType('company')->create(['name' => 'Vertex Foods']);
        $west = OrgUnit::factory()->under($company, 'region')->create(['name' => 'West region']);

        return OrgUnit::factory()->under($west, 'plant')->create(['name' => 'Nashik plant']);
    }

    private function office(): Office
    {
        return Office::factory()->named('Nashik office')->in('IN', 'IN-MH')->create();
    }

    /**
     * Four people, because seven clearances do not need seven pairs of hands and a real
     * plant of this size does not have them. Meera holds three of the seven and is also
     * the leaver's manager, so signing in as her shows four different forms side by side
     * — which is the fastest way to see that the engine knows nothing about any of them.
     *
     * @return array<string, User>
     */
    private function people(OrgUnit $plant, Office $office): array
    {
        $designations = [
            'head' => Designation::factory()->named('Head of People')->create(),
            'manager' => Designation::factory()->named('Plant Manager')->create(),
            'lead' => Designation::factory()->named('Systems Lead')->create(),
            'executive' => Designation::factory()->named('Quality Executive')->create(),
        ];

        $meera = $this->person('Meera Joshi', $plant, $office, $designations['head']);

        return [
            'meera' => $meera,
            'sanjay' => $this->person('Sanjay Kulkarni', $plant, $office, $designations['manager'], $meera),
            'farhan' => $this->person('Farhan Qureshi', $plant, $office, $designations['lead'], $meera),

            // The leaver. Her last working day is the date the legal clock counts from,
            // and it is the one line on the panel that is blank on everybody else.
            'neha' => $this->person(
                'Neha Deshpande', $plant, $office, $designations['executive'], $meera,
                lastWorkingDay: now()->addDays(3)->toDateString(),
            ),
        ];
    }

    private function person(
        string $name,
        OrgUnit $plant,
        Office $office,
        Designation $designation,
        ?User $manager = null,
        ?string $lastWorkingDay = null,
    ): User {
        [$first] = explode(' ', $name, 2);

        $person = User::factory()->named($name)->create([
            'work_email' => strtolower($first).'@vertex.test',
            'personal_email' => strtolower($first).'@personal.example',
            'password' => MeridianSeeder::Password,
        ]);

        $row = EmploymentRecord::factory()->forPerson($person)
            ->in($plant)->basedAt($office)->designated($designation)
            ->state(['last_working_day' => $lastWorkingDay]);

        ($manager === null ? $row : $row->reportingTo($manager))->create();

        return $person;
    }

    /**
     * A role for each department that clears an exit, plus the administrator every client
     * company keeps. Each carries the actions the same role really carries on a company
     * created through the platform, so the screens that ask before they open are not shut
     * to everybody.
     *
     * @param  array<string, User>  $people
     */
    private function rolesTheyHold(array $people): void
    {
        $starter = StarterRoles::definitions();

        // A clearance holder needs to see the person whose exit they are clearing, and
        // nothing more. That is the finance approver's list, and it is what the six
        // department roles take.
        $clearing = $starter['finance_approver']['permissions'];

        $roles = [
            'hr_head' => ['HR head', $starter['hr_head']['permissions'], ['meera']],
            'admin_services' => ['Admin services', $clearing, ['meera']],
            'booking_admin' => ['Booking desk', $clearing, ['meera']],
            'finance_head' => ['Finance head', $clearing, ['sanjay']],
            'crm_head' => ['CRM head', $clearing, ['sanjay']],
            'it_support' => ['IT support', $clearing, ['farhan']],

            // Two of them, because a company keeping only one administrator can never
            // take the role away again — the product refuses the removal that would lock
            // them out of their own account management.
            Role::AdministratorKey => ['Administrator', $starter[Role::AdministratorKey]['permissions'], ['meera', 'sanjay']],
        ];

        foreach ($roles as $key => [$name, $permissions, $holders]) {
            $role = Role::factory()->keyed($key, $name)->withPermissions($permissions)->create();

            foreach ($holders as $holder) {
                $role->assignments()->create([
                    'user_id' => $people[$holder]->getKey(),
                    'org_unit_id' => null,
                    'includes_descendants' => false,
                ]);
            }
        }
    }

    /**
     * Vertex's exit: seven department clearances, all in one group, so every department
     * clears at the same time rather than queueing behind each other. That is how the old
     * tool ran them — each department had its own pending list and none of them waited on
     * another — and it is the shape Meridian's one-at-a-time exit cannot show.
     *
     * The reporting manager's clearance goes to the leaver's own manager rather than to a
     * role, which is the one of the seven that is nobody's job description.
     */
    private function exitProcess(): ProcessTemplate
    {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();
        $sequence = 0;

        foreach (self::Clearances as $key => $clearance) {
            $step = ProcessStep::factory()->of($exit)
                ->at(++$sequence, 1)
                ->named($clearance['name'])
                ->asking($this->clearanceForm($key, $clearance))
                ->offering('approved', 'rejected')
                ->dueIn(48);

            ($clearance['held_by'] === null
                ? $step->state(['assignee_rule' => ['kind' => 'reporting_manager']])
                : $step->heldByTheRoleAnywhere($clearance['held_by'])
            )->create();
        }

        $exit->publish();

        return $exit;
    }

    /**
     * One clearance form, built from its rows.
     *
     * This method is the claim the whole module rests on. It has no idea what any of the
     * seven forms asks: the same handful of lines produce a three-question clearance and a
     * thirteen-question one, a question that stops the clearance and a question that only
     * appears once somebody says an asset did not come back.
     *
     * @param  array{name: string, held_by: ?string, asks: list<array<string, mixed>>}  $clearance
     */
    private function clearanceForm(string $key, array $clearance): FormDefinition
    {
        $form = FormDefinition::factory()->named($key, $clearance['name'])->create();

        foreach ($clearance['asks'] as $order => $question) {
            $field = FormField::factory()->on($form)->at($order + 1)
                ->asking($question['key'], $question['label'], $question['type']);

            if ($question['required'] ?? false) {
                $field = $field->required();
            }

            if (isset($question['when'])) {
                $field = $field->askedWhen(...$question['when']);
            }

            $field->create();
        }

        $form->publish();

        return $form;
    }
}
