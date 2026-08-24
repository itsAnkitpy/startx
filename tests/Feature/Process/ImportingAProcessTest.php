<?php

use App\Exceptions\ProcessRefused;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Process\FlatFile;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use App\Tenancy\TenantContext;

/*
| Step 5 of module 02: typing a client's process into a spreadsheet and running it.
|
| This is the commercial claim, not a convenience. A client's process arrives as a
| document at a kickoff meeting, and the time between that meeting and their process
| running is most of what decides whether they stay. So the test that matters is the
| round trip — a process written out and read back has to be the same process — because
| a file written by hand to pass proves only that the file was written to pass.
|
| Two rules run through all of it. An import always lands as a draft, so nothing here can
| reach a running case and making it live is what checks it. And a file with one bad row
| imports nothing at all: a customer list missing a customer is a shorter list, while an
| exit missing its Finance clearance reaches the end with a department never asked.
*/

beforeEach(function () {
    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
    $this->files = [];
});

afterEach(function () {
    Settings::forgetDeclared();

    foreach ($this->files as $path) {
        @unlink($path);
    }
});

/** A file on disk holding what somebody typed, cleaned up when the test ends. */
function aProcessFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'startx-process-').'.csv';
    file_put_contents($path, $contents);

    $written = test()->files;
    $written[] = $path;
    test()->files = $written;

    return $path;
}

/**
 * Vertex Foods' exit, as somebody would type it: different departments from Meridian's,
 * a clearance pair running side by side, a director approval on a client setting, a step
 * the leaver themselves does, and one step that opens on either of two sets of
 * conditions and is therefore written twice.
 */
function vertexsExitAsTyped(): string
{
    return <<<'CSV'
    sequence,group,step_name,assignee,form_key,outcomes,sla_hours,nudge_at,open_when,participant,notify
    1,1,Resignation acknowledged,role_in_scope:hr,ack_form,approved,24,,,internal,
    2,2,Plant manager approval,reporting_manager,,"approved,rejected,sent_back",48,"0.5,0.75",,internal,exit_manager_asked
    3,3,Stores clearance,role_in_scope:stores,,"approved,held",24,,,internal,
    4,3,Quality clearance,role_in_scope:quality,,"approved,held",24,,,internal,
    5,4,Director approval,role_global:director,,"approved,rejected",48,,payload.annual_ctc > setting.director_threshold,internal,
    6,5,Handover note from the leaver,external,,approved,,,payload.handover_needed is_set,external,
    7,6,Recovery of company property,role_in_scope:admin,,"approved,held",,,"subject.office_id in 4,7",internal,
    7,6,Recovery of company property,role_in_scope:admin,,"approved,held",,,payload.assets_held = true,internal,
    8,7,Final settlement,role_in_scope:finance,,approved,,,,internal,
    CSV;
}

/** What a round trip has to give back unchanged. */
function stepsToCompare(ProcessTemplate $template): array
{
    return $template->steps->map(fn (ProcessStep $step): array => [
        'sequence' => $step->sequence,
        'group_no' => $step->group_no,
        'name' => $step->name,
        'participant_kind' => $step->participant_kind,
        'assignee_rule' => $step->assignee_rule,
        'open_conditions' => $step->open_conditions,
        'allowed_outcomes' => $step->allowed_outcomes,
        'sla_hours' => $step->sla_hours,
        'reminder_rule' => $step->reminder_rule,
    ])->all();
}

/** The one Vertex's director approval hangs on. */
function declareVertexsDirectorThreshold(): void
{
    Settings::declare(new SettingDeclaration(
        key: 'director_threshold',
        type: 'integer',
        default: 1500000,
        rule: 'integer|min:0',
        help: 'Exits above this annual figure need the director.',
    ));
}

it("reads a client's process out of a flat file as a draft", function () {
    $this->artisan('process:import', [
        'file' => aProcessFile(vertexsExitAsTyped()),
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertSuccessful();

    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::query()->where('key', 'exit')->sole();

        expect($exit->status)->toBe(ProcessTemplate::Draft)
            ->and($exit->version)->toBe(1)
            ->and($exit->name)->toBe('Exit');

        $steps = $exit->steps;

        // Eight steps from nine rows: the recovery step is written twice because it
        // opens on either of two sets of conditions.
        expect($steps)->toHaveCount(8);

        expect($steps[0]->name)->toBe('Resignation acknowledged')
            ->and($steps[0]->participant_kind)->toBe('internal')
            ->and($steps[0]->assignee_rule)->toEqual(['kind' => 'role_in_scope', 'role' => 'hr'])
            ->and($steps[0]->sla_hours)->toBe(24)
            ->and($steps[0]->allowed_outcomes)->toEqual(['approved'])
            ->and($steps[0]->reminder_rule)->toBeNull();

        expect($steps[1]->assignee_rule)->toEqual(['kind' => 'reporting_manager'])
            ->and($steps[1]->reminder_rule)->toEqual(['nudge_at' => [0.5, 0.75]])
            ->and($steps[1]->allowed_outcomes)->toEqual(['approved', 'rejected', 'sent_back']);

        // Steps 3 and 4 share a group, which is the whole shape of a parallel clearance.
        expect($steps[2]->group_no)->toBe(3)->and($steps[3]->group_no)->toBe(3);

        // A threshold that is a client setting rather than a number typed into the row.
        expect($steps[4]->open_conditions)->toEqual([[
            ['source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>', 'setting' => 'director_threshold'],
        ]]);

        // The leaver has no account here, and both halves of the step say so: nobody is
        // looked for, and nobody is expected to have an account.
        expect($steps[5]->participant_kind)->toBe('external')
            ->and($steps[5]->assignee_rule)->toEqual(['kind' => 'external'])
            ->and($steps[5]->open_conditions)->toEqual([[
                ['source' => 'payload', 'field' => 'handover_needed', 'operator' => 'is_set'],
            ]]);

        expect($steps[7]->sla_hours)->toBeNull();
    });
});

it('makes one step of two rows when a step opens on either of two sets of conditions', function () {
    $this->artisan('process:import', [
        'file' => aProcessFile(vertexsExitAsTyped()),
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertSuccessful();

    TenantContext::run($this->meridian, function () {
        $recovery = ProcessStep::query()->where('sequence', 7)->sole();

        // A list of offices on one side and a plain true on the other, so the step opens
        // for anyone at those two plants and for anyone holding company property.
        expect($recovery->name)->toBe('Recovery of company property')
            ->and($recovery->open_conditions)->toEqual([
                [['source' => 'subject', 'field' => 'office_id', 'operator' => 'in', 'value' => [4, 7]]],
                [['source' => 'payload', 'field' => 'assets_held', 'operator' => '=', 'value' => true]],
            ])
            ->and($recovery->open_conditions[0][0]['value'])->toEqual([4, 7]);
    });
});

it('gives back the same process when one is written out and read back in', function () {
    declareVertexsDirectorThreshold();

    $typed = aProcessFile(vertexsExitAsTyped());

    $this->artisan('process:import', [
        'file' => $typed,
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertSuccessful();

    // Made live before it is written out, which is both how a client's process actually
    // gets used and the thing the adoption claim rests on: a typed file runs with no
    // further editing. It is also what leaves no unfinished draft for the read-back.
    TenantContext::run($this->meridian, fn () => ProcessTemplate::query()->where('key', 'exit')->sole()->publish());

    $written = aProcessFile('');

    $this->artisan('process:export', ['key' => 'exit', '--tenant' => 'meridian', '--file' => $written])
        ->assertSuccessful();

    // Read back as the next version of the same process, which is also how a process
    // moves between two of a client's environments.
    $this->artisan('process:import', ['file' => $written, '--tenant' => 'meridian', '--key' => 'exit'])
        ->assertSuccessful();

    TenantContext::run($this->meridian, function () {
        $versions = ProcessTemplate::query()->where('key', 'exit')->orderBy('version')->with('steps')->get();

        expect($versions)->toHaveCount(2)
            ->and($versions[0]->status)->toBe(ProcessTemplate::Published)
            // Its own name and its own subject carried over rather than retyped, so a
            // round trip cannot rename a process or change who it is about by omission.
            ->and($versions[1]->name)->toBe('Exit')
            ->and($versions[1]->subject_kind)->toBe('employee')
            ->and($versions[1]->status)->toBe(ProcessTemplate::Draft);

        // Compared as values rather than as text: Postgres sorts the keys inside a
        // condition, so the same condition is stored in another order than it was typed.
        expect(stepsToCompare($versions[1]))->toEqual(stepsToCompare($versions[0]));
    });
});

it('imports nothing at all from a file with a bad row, and names every bad row with its line', function () {
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,outcomes,sla_hours,open_when
    1,1,Resignation acknowledged,role_in_scope:hr,approved,24,
    2,2,Plant manager approval,line_manager,approved,48,
    3,3,Stores clearance,role_in_scope:stores,"approved,cancelled",24,
    4,4,Director approval,role_global:director,approved,48,payload.annual_ctc is greater than 1500000
    5,5,Final settlement,role_in_scope:finance,approved,,
    CSV);

    $refusal = null;

    try {
        (new FlatFile)->read($file);
    } catch (ProcessRefused $caught) {
        $refusal = $caught->getMessage();
    }

    // All three together rather than one round trip through the file each: an unknown
    // resolver, an outcome nobody can choose, and a condition that is not a condition.
    expect($refusal)->toContain('Line 3')->toContain('line_manager')
        ->toContain('Line 4')->toContain('cancelled')
        ->toContain('Line 5')->toContain('is greater than');

    $this->artisan('process:import', [
        'file' => $file,
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertFailed();

    // Not four steps and not a draft holding them: nothing.
    TenantContext::run($this->meridian, function () {
        expect(ProcessTemplate::query()->count())->toBe(0)
            ->and(ProcessStep::query()->count())->toBe(0);
    });
});

it('refuses to import while an unfinished draft of that process is already open', function () {
    $arguments = [
        'file' => aProcessFile(vertexsExitAsTyped()),
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ];

    $this->artisan('process:import', $arguments)->assertSuccessful();
    $this->artisan('process:import', $arguments)->assertFailed();

    TenantContext::run($this->meridian, function () {
        expect(ProcessTemplate::query()->where('key', 'exit')->count())->toBe(1);
    });
});

it('reads a file saved out of Excel', function () {
    // The three bytes Excel writes in front of the first character, Windows line
    // endings, a comma inside a quoted cell, and the blank line every spreadsheet
    // leaves at the end.
    $file = aProcessFile("\xEF\xBB\xBF".implode("\r\n", [
        'sequence,group,step_name,assignee,outcomes',
        '1,1,"Approval, final",role_in_scope:hr,"approved,rejected"',
        '',
        '',
    ]));

    $steps = (new FlatFile)->read($file);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['name'])->toBe('Approval, final')
        ->and($steps[0]['allowed_outcomes'])->toEqual(['approved', 'rejected']);
});

it('refuses a misspelled column heading rather than quietly dropping what was under it', function () {
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,sla_hour
    1,1,Resignation acknowledged,role_in_scope:hr,24
    CSV);

    expect(fn () => (new FlatFile)->read($file))
        ->toThrow(ProcessRefused::class, 'sla_hour');
});

it('checks an imported draft the same way it checks one built by hand', function () {
    declareVertexsDirectorThreshold();

    // The transposition a typed spreadsheet produces: the director's approval is written
    // above HR's close and given the later group, so HR would close the exit first.
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,outcomes,sla_hours,open_when
    1,1,Resignation acknowledged,role_in_scope:hr,approved,24,
    2,3,Director approval,role_global:director,approved,48,payload.annual_ctc > setting.director_threshold
    3,2,HR close,role_in_scope:hr,approved,24,
    CSV);

    $this->artisan('process:import', [
        'file' => $file,
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertSuccessful();

    // Imported happily, because the importer does no validating of its own. Going live
    // is what catches it, exactly as it would on a process somebody built by hand.
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::query()->where('key', 'exit')->sole();

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'which is written above it');
    });
});

it('takes a file holding only the four columns every row needs', function () {
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee
    1,1,Manager approval,reporting_manager
    CSV);

    $this->artisan('process:import', [
        'file' => $file,
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertSuccessful();

    // The adoption rule in this module's plan, arriving through the importer: a step is
    // a name and whose it is, and everything else has a safe default. Every one of these
    // is what the client would have had to decide before their process could run.
    TenantContext::run($this->meridian, function () {
        $step = ProcessStep::query()->sole();

        expect($step->participant_kind)->toBe('internal')
            ->and($step->allowed_outcomes)->toEqual(['approved', 'rejected'])
            ->and($step->sla_hours)->toBeNull()
            ->and($step->reminder_rule)->toBeNull()
            ->and($step->open_conditions)->toEqual([]);
    });
});

it('refuses two rows for one step that do not otherwise say the same thing', function () {
    // Copied the row and changed one cell, which is how a spreadsheet gets written. The
    // deadline on the second row would otherwise be lost in silence.
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,sla_hours,open_when
    1,1,Recovery of company property,role_in_scope:admin,24,payload.assets_held = true
    1,1,Recovery of company property,role_in_scope:admin,48,subject.equipment_issued = true
    CSV);

    expect(fn () => (new FlatFile)->read($file))
        ->toThrow(ProcessRefused::class, 'do not otherwise say the same thing');
});

it('refuses two rows for one step where only one of them says when the step opens', function () {
    // A step already opening on every case does not also open on a condition, so one of
    // these two rows is a mistake and neither can be assumed to be the right one.
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,open_when
    1,1,Recovery of company property,role_in_scope:admin,payload.assets_held = true
    1,1,Recovery of company property,role_in_scope:admin,
    CSV);

    expect(fn () => (new FlatFile)->read($file))
        ->toThrow(ProcessRefused::class, 'both rows need one');
});

it('refuses a file whose heading is missing a column every row needs', function () {
    // Nobody is named on any step, so every one of them would belong to whoever the
    // engine guessed — which is nobody, and the process would publish with no owners.
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name
    1,1,Resignation acknowledged
    CSV);

    expect(fn () => (new FlatFile)->read($file))
        ->toThrow(ProcessRefused::class, 'no [assignee] column');
});

it('reads a number typed into a cell as a number', function () {
    // Asserted before the row reaches the database, which is the only place the
    // difference is visible: a threshold stored as the text "1500000" is refused at
    // publish, and one stored as a number is not. Postgres writes both back as 1500000.
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,open_when
    1,1,Director approval,role_global:director,payload.annual_ctc > 1500000 and payload.performance_rating >= 4.5
    CSV);

    $steps = (new FlatFile)->read($file);

    expect($steps[0]['open_conditions'][0][0]['value'])->toBe(1500000)
        ->and($steps[0]['open_conditions'][0][1]['value'])->toBe(4.5);
});

it('reads true and false however the spreadsheet capitalised them', function () {
    // Asserted on what the reader returns rather than on the stored row, because the
    // difference is only visible before the database. As the text "FALSE" the condition
    // lands the wrong way round: PHP reads any non-empty text as true, so a step written
    // to be skipped when nothing is held would run only when something is.
    $file = aProcessFile(<<<'CSV'
    sequence,group,step_name,assignee,open_when
    1,1,Recovery of company property,role_in_scope:admin,payload.assets_held = FALSE
    2,2,Handover note,role_in_scope:hr,Payload.handover_needed = True
    3,3,Director approval,role_global:director,payload.annual_ctc > Setting.director_threshold
    4,4,Recovery of company property,role_in_scope:admin,"subject.office_id IN 4,7"
    CSV);

    $steps = (new FlatFile)->read($file);

    expect($steps[0]['open_conditions'][0][0]['value'])->toBeFalse()
        ->and($steps[1]['open_conditions'][0][0]['value'])->toBeTrue()
        // The first word of a cell is what a spreadsheet capitalises on its own, and the
        // name of a client setting is a word this grammar owns rather than a value.
        ->and($steps[1]['open_conditions'][0][0]['source'])->toBe('payload')
        ->and($steps[2]['open_conditions'][0][0]['setting'])->toBe('director_threshold')
        // A comparison against a list of two offices, not against the text "4,7".
        ->and($steps[3]['open_conditions'][0][0]['operator'])->toBe('in')
        ->and($steps[3]['open_conditions'][0][0]['value'])->toBe([4, 7]);
});

it('names the line when a step name is longer than a step name can hold', function () {
    // 350 characters. Left to the insert this fails partway down the file with a database
    // error and no line number, which is the one thing the reader promises.
    $file = aProcessFile(
        "sequence,group,step_name,assignee\n"
        ."1,1,Resignation acknowledged,role_in_scope:hr\n"
        .'2,2,'.str_repeat('Confirmation from the plant manager ', 10).",reporting_manager\n"
    );

    expect(fn () => (new FlatFile)->read($file))
        ->toThrow(ProcessRefused::class, 'Line 3');

    $this->artisan('process:import', [
        'file' => $file,
        '--tenant' => 'meridian',
        '--key' => 'exit',
        '--name' => 'Exit',
        '--about' => 'employee',
    ])->assertFailed();

    TenantContext::run($this->meridian, function () {
        expect(ProcessTemplate::query()->count())->toBe(0);
    });
});
