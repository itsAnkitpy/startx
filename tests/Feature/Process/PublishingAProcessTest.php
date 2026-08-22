<?php

use App\Exceptions\ProcessRefused;
use App\Models\CaseStep;
use App\Models\ProcessCase;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\SettingDeclaration;
use App\Settings\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Step 2 of module 02: making a process live, and what that then forbids.
|
| Two halves. The lock — a live version's steps are frozen for good, one process has one
| live version, and editing a live process starts the next version rather than changing
| this one. And the checking that happens at the moment of going live, because these are
| the failures nobody can see at run time: a condition that is quietly false skips the
| step it guards, and a skipped approval leaves no error and no missing screen.
|
| A refused write abandons the surrounding transaction in Postgres, which under
| RefreshDatabase is the test's own, so each expected database refusal gets a test to
| itself.
*/

beforeEach(function () {
    Settings::forgetDeclared();

    $this->meridian = Tenant::factory()->create(['name' => 'Meridian Logistics', 'slug' => 'meridian']);
});

afterEach(function () {
    Settings::forgetDeclared();
});

/**
 * Meridian's exit as a draft: the manager, then IT and Finance clearing side by side.
 */
function meridiansExitDraft(): ProcessTemplate
{
    $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

    ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create();
    ProcessStep::factory()->of($exit)->at(2, 2)->named('IT clearance')->clearance()->create();
    ProcessStep::factory()->of($exit)->at(3, 2)->named('Finance clearance')->clearance()->create();

    return $exit;
}

/**
 * The sort of switch module 05 will really declare: the salary above which a hiring
 * request needs the director.
 */
function declareDirectorThreshold(string $type = 'integer', mixed $default = 1500000, string $rule = 'integer|min:0'): void
{
    Settings::declare(new SettingDeclaration(
        key: 'hiring_director_threshold',
        type: $type,
        default: $default,
        rule: $rule,
        help: 'Hires above this annual figure need the director.',
    ));
}

/*
| Going live, and the lock it puts on
*/

it('makes a draft live and freezes its steps for good', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();

        $exit->publish();

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Published);

        // Straight past the model, which is the only way this happens by mistake.
        expect(fn () => DB::update(
            'update process_steps set name = ? where template_id = ? and sequence = 1',
            ['Head of Freight approval', $exit->getKey()]
        ))->toThrow(QueryException::class, 'its steps cannot be changed');
    });
});

it('refuses a step added to a live version', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();
        $exit->publish();

        ProcessStep::factory()->of($exit)->at(4, 3)->named('Payroll clearance')->create();
    });
})->throws(QueryException::class, 'its steps cannot be changed');

it('refuses a step removed from a live version', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();
        $exit->publish();

        $exit->steps()->where('sequence', 3)->first()->delete();
    });
})->throws(QueryException::class, 'its steps cannot be changed');

it('refuses a step changed on a retired version', function () {
    // Retired is frozen too. Cases that ran on it are still read years later, and what
    // they show has to be what was true, so an old version can never be tidied up.
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();
        $exit->publish();

        $second = $exit->draftNextVersion();
        $second->publish();

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Archived);

        DB::update(
            'update process_steps set name = ? where template_id = ?',
            ['Anything at all', $exit->getKey()]
        );
    });
})->throws(QueryException::class, 'its steps cannot be changed');

it('refuses to delete a version somebody is running on', function () {
    // Not the freeze — deleting the version cascades onto its steps, and by then the
    // version row is gone, so the trigger reads no status. What holds here is the key
    // from the case, which will not let go of the version it is reading its process from.
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();
        $exit->publish();

        ProcessCase::factory()->on($exit)->create();

        $exit->delete();
    });
})->throws(QueryException::class, 'cases_tenant_id_template_id_foreign');

it('still lets a draft be edited and thrown away', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();

        $exit->steps()->where('sequence', 1)->first()->update(['name' => 'Head of Freight approval']);
        $exit->steps()->where('sequence', 3)->first()->delete();

        expect($exit->fresh()->steps->pluck('name')->all())
            ->toBe(['Head of Freight approval', 'IT clearance']);

        $exit->delete();

        expect(ProcessStep::query()->count())->toBe(0);
    });
});

/*
| One live version at a time
*/

it('retires the version it replaces', function () {
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        $second = $first->draftNextVersion();
        $second->publish();

        expect($first->fresh()->status)->toBe(ProcessTemplate::Archived)
            ->and($second->fresh()->status)->toBe(ProcessTemplate::Published)
            ->and($second->version)->toBe(2);
    });
});

it('refuses two live versions of one process at the database', function () {
    // With two live, the version a new exit opens on is whichever the database happens
    // to return first — Rakesh's exit and Chandni's running different approval chains
    // with nothing on either screen to explain why.
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        DB::update(
            "update process_templates set status = 'published' where key = 'exit' and version = 1"
        );

        $second = ProcessTemplate::factory()->named('exit', 'Exit')->version(2)->create();

        DB::update(
            "update process_templates set status = 'published' where id = ?",
            [$second->getKey()]
        );
    });
})->throws(QueryException::class, 'process_templates_one_published_version');

it('lets two different processes each be live', function () {
    TenantContext::run($this->meridian, function () {
        meridiansExitDraft()->publish();

        $onboarding = ProcessTemplate::factory()->named('onboarding', 'Onboarding')->about('candidate')->create();
        ProcessStep::factory()->of($onboarding)->at(1, 1)->named('Collect details')->external()->create();
        $onboarding->publish();

        expect(ProcessTemplate::query()->where('status', ProcessTemplate::Published)->count())->toBe(2);
    });
});

/*
| Editing a live process
*/

it('copies a live version into a new draft and leaves the live one alone', function () {
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        $second = $first->draftNextVersion();
        $second->steps()->where('sequence', 1)->first()->update(['name' => 'Head of Freight approval']);

        expect($second->status)->toBe(ProcessTemplate::Draft)
            ->and($second->version)->toBe(2)
            ->and($second->key)->toBe('exit')
            ->and($second->fresh()->steps->pluck('name')->all())
            ->toBe(['Head of Freight approval', 'IT clearance', 'Finance clearance']);

        // The live version is untouched, including the step whose copy was renamed.
        expect($first->fresh()->steps->pluck('name')->all())
            ->toBe(['Manager approval', 'IT clearance', 'Finance clearance']);
    });
});

it("carries a step's whole definition into the new version, not just its name", function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Finance clearance')->clearance()->create([
            'sla_hours' => 16,
            'open_conditions' => [[['source' => 'subject', 'field' => 'has_assets', 'operator' => '=', 'value' => true]]],
        ]);

        $exit->publish();

        $copy = $exit->draftNextVersion()->steps->first();

        expect($copy->allowed_outcomes)->toBe(['approved', 'held'])
            ->and($copy->sla_hours)->toBe(16)
            // Compared loosely on purpose: Postgres sorts a jsonb object's keys, so the
            // copy reads back with them in a different order than they were written.
            ->and($copy->open_conditions)
            ->toEqual([[['source' => 'subject', 'field' => 'has_assets', 'operator' => '=', 'value' => true]]]);
    });
});

it('leaves a running case reading the version it opened on', function () {
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        // Rakesh resigns on version 1 and his manager's step is already claimed.
        $rakesh = User::factory()->named('Rakesh Menon')->create();
        $case = ProcessCase::factory()->on($first)->create(['subject_user_id' => $rakesh->getKey()]);
        CaseStep::factory()->of($case)->at(1)->heldBy($rakesh)->create();

        // Anjali changes the approver and makes version 2 live the same afternoon.
        $second = $first->draftNextVersion();
        $second->steps()->where('sequence', 1)->first()->update(['name' => 'Head of Freight approval']);
        $second->publish();

        expect($case->fresh()->template->version)->toBe(1)
            ->and($case->fresh()->template->steps->pluck('name')->all())
            ->toBe(['Manager approval', 'IT clearance', 'Finance clearance']);
    });
});

it('refuses to start a new version beside a draft', function () {
    TenantContext::run($this->meridian, function () {
        meridiansExitDraft()->draftNextVersion();
    });
})->throws(ProcessRefused::class, 'is still a draft, so edit it directly');

it('refuses a second unfinished draft beside the first', function () {
    // Two clicks on the edit button used to make two copies of the live version. Anjali
    // fixes the approver on one and switches it on; somebody switches the other on a
    // week later and her fix is silently back to what it was.
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        $second = $first->draftNextVersion();

        expect($second->version)->toBe(2);

        $first->draftNextVersion();
    });
})->throws(ProcessRefused::class, 'already has version 2 as an unfinished draft');

it('starts the next version again once the draft has been finished', function () {
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        $second = $first->draftNextVersion();
        $second->publish();

        expect($second->draftNextVersion()->version)->toBe(3);
    });
});

it('refuses to make a live version live a second time', function () {
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();
        $exit->publish();

        $exit->publish();
    });
})->throws(ProcessRefused::class, 'is published, and only a draft can be made live');

/*
| The checking that happens at the moment of going live
*/

it('refuses a process with no steps', function () {
    TenantContext::run($this->meridian, function () {
        ProcessTemplate::factory()->named('exit', 'Exit')->create()->publish();
    });
})->throws(ProcessRefused::class, 'has no steps');

it('refuses a condition about the person on a process that is about nobody', function () {
    // A hiring request is about a vacant position. At run time the subject snapshot is
    // empty, so the condition is false, so the step it guards silently never happens.
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[['source' => 'subject', 'field' => 'org_unit_id', 'operator' => '=', 'value' => 4]]],
        ]);

        $hiring->publish();
    });
})->throws(ProcessRefused::class, 'Step 1 "Director approval" asks about the person this process is about, and this process is about nobody');

it('allows the same condition on a process that is about somebody', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->about('employee')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Admin clearance')->create([
            'open_conditions' => [[['source' => 'subject', 'field' => 'org_unit_id', 'operator' => '=', 'value' => 4]]],
        ]);

        $exit->publish();

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Published);
    });
});

it('refuses a condition naming a client setting this system does not have', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>',
                'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        $hiring->publish();
    });
})->throws(ProcessRefused::class, 'which is not a setting this system has');

it('refuses a larger-than comparison against a client setting that holds text', function () {
    // The reason module 01's settings registry keeps a declared kind at all. At run time
    // a number compared against text is a silent false and one more approval nobody sees.
    TenantContext::run($this->meridian, function () {
        declareDirectorThreshold(type: 'text', default: 'fifteen lakh', rule: 'string');

        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>',
                'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        $hiring->publish();
    });
})->throws(ProcessRefused::class, 'which holds text rather than a number');

it('allows that comparison once the setting holds a number', function () {
    TenantContext::run($this->meridian, function () {
        declareDirectorThreshold();

        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>',
                'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        $hiring->publish();

        expect($hiring->fresh()->status)->toBe(ProcessTemplate::Published);
    });
});

it('refuses a condition with nothing to compare against, and one with two things', function () {
    TenantContext::run($this->meridian, function () {
        declareDirectorThreshold();

        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[['source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>']]],
        ]);

        ProcessStep::factory()->of($hiring)->at(2, 2)->named('Board approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>',
                'value' => 5000000, 'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        expect(fn () => $hiring->publish())
            ->toThrow(ProcessRefused::class, 'Step 1 "Director approval" has a condition with nothing to compare against')
            ->and(fn () => $hiring->publish())
            ->toThrow(ProcessRefused::class, 'Step 2 "Board approval" has a condition comparing against both');
    });
});

it('refuses an operator and a source outside the allowed list', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create([
            'open_conditions' => [[[
                'source' => 'live_employee', 'field' => 'annual_ctc', 'operator' => 'contains', 'value' => 'x',
            ]]],
        ]);

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'which is not something a condition can ask about')
            ->and(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, '[contains], which is not one of');
    });
});

it('names every problem at once rather than the first', function () {
    // Anjali fixing one, republishing, and being told about the next is six round trips
    // through a screen she is using for the first time.
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[
                ['source' => 'subject', 'field' => 'org_unit_id', 'operator' => '=', 'value' => 4],
                ['source' => 'payload', 'field' => '', 'operator' => 'sounds_like', 'value' => 'x'],
            ]],
        ]);

        try {
            $hiring->publish();
        } catch (ProcessRefused $refused) {
            expect($refused->getMessage())
                ->toContain('asks about the person this process is about')
                ->toContain('does not say which field it is about')
                ->toContain('[sounds_like], which is not one of')
                ->and(substr_count($refused->getMessage(), "\n  - "))->toBe(3);

            return;
        }

        throw new RuntimeException('Publishing should have been refused.');
    });
});

it('leaves the process a draft when it is refused', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        expect(fn () => $exit->publish())->toThrow(ProcessRefused::class);

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Draft);
    });
});

it('accepts a step that only asks whether a field was answered', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Notice buyout approval')->create([
            'open_conditions' => [[['source' => 'payload', 'field' => 'buyout_days', 'operator' => 'is_set']]],
        ]);

        $exit->publish();

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Published);
    });
});

it('refuses a step asking whether a field was answered and comparing it as well', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Notice buyout approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'buyout_days', 'operator' => 'is_set', 'value' => 30,
            ]]],
        ]);

        $exit->publish();
    });
})->throws(ProcessRefused::class, 'asking only whether a field was answered');

it('refuses a larger-than comparison against a figure typed as words', function () {
    // The same failure as the text-setting test above, arriving through the other door.
    // Anjali types the threshold as "fifteen lakh" instead of the number: at run time the
    // comparison is quietly false, so the director's step never appears and the case
    // closes approved with nobody having approved it.
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>', 'value' => 'fifteen lakh',
            ]]],
        ]);

        $hiring->publish();
    });
})->throws(ProcessRefused::class, 'compares with [>] against [fifteen lakh], which is not a number');

it('allows that comparison once the figure is written as a number', function () {
    TenantContext::run($this->meridian, function () {
        $hiring = ProcessTemplate::factory()->named('hiring', 'Hiring request')->about('none')->create();

        ProcessStep::factory()->of($hiring)->at(1, 1)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>', 'value' => 1500000,
            ]]],
        ]);

        $hiring->publish();

        expect($hiring->fresh()->status)->toBe(ProcessTemplate::Published);
    });
});

it('refuses an is-one-of comparison against a single value rather than a list', function () {
    TenantContext::run($this->meridian, function () {
        declareDirectorThreshold();

        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Admin clearance')->create([
            'open_conditions' => [[[
                'source' => 'subject', 'field' => 'designation_id', 'operator' => 'in', 'value' => 7,
            ]]],
        ]);

        // And the same question asked of a client setting, which holds one value and can
        // never be a list whatever kind it is declared as.
        ProcessStep::factory()->of($exit)->at(2, 2)->named('Director approval')->create([
            'open_conditions' => [[[
                'source' => 'payload', 'field' => 'annual_ctc', 'operator' => 'not_in',
                'setting' => 'hiring_director_threshold',
            ]]],
        ]);

        expect(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'compares with [in] against [7], which is not a list of values')
            ->and(fn () => $exit->publish())
            ->toThrow(ProcessRefused::class, 'holds a single value rather than a list of them');
    });
});

it('refuses an empty group of conditions in words that say what is wrong with it', function () {
    // It used to be told it "has a group of conditions that is not a list of conditions",
    // which is nonsense — an empty list is a list. The objection is that it is always
    // true, so the step it guards is not conditional at all.
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create([
            'open_conditions' => [[]],
        ]);

        $exit->publish();
    });
})->throws(ProcessRefused::class, 'has an empty group of conditions, which is always true');

it('checks the steps as they are now, not as a screen last listed them', function () {
    // A screen renders the step list and publishes in the same click. The broken step
    // added in between used to go unchecked, and was then frozen into a live version
    // for good.
    TenantContext::run($this->meridian, function () {
        $exit = meridiansExitDraft();

        $exit->load('steps');

        ProcessStep::factory()->of($exit)->at(4, 3)->named('Director approval')->create([
            'open_conditions' => [[['source' => 'nowhere', 'field' => 'x', 'operator' => '=', 'value' => 1]]],
        ]);

        $exit->publish();
    });
})->throws(ProcessRefused::class, 'Step 4 "Director approval" has a condition about [nowhere]');

it('sees steps added after an empty list was loaded', function () {
    // The mirror of the case above: publishing used to refuse a process that does have
    // steps, because the list was loaded while it was still empty.
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        $exit->load('steps');

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create();

        $exit->publish();

        expect($exit->fresh()->status)->toBe(ProcessTemplate::Published);
    });
});

it('refuses a group of conditions that is not a list of conditions', function () {
    TenantContext::run($this->meridian, function () {
        $exit = ProcessTemplate::factory()->named('exit', 'Exit')->create();

        ProcessStep::factory()->of($exit)->at(1, 1)->named('Manager approval')->create([
            'open_conditions' => [['source' => 'payload', 'field' => 'annual_ctc', 'operator' => '>', 'value' => 1]],
        ]);

        $exit->publish();
    });
})->throws(ProcessRefused::class, 'has a group of conditions that is not a list of conditions');

it('builds test cases on a process the product could actually have produced', function () {
    // The fixture used to write the live status straight onto the row, which produced a
    // live process with no steps in it — a shape publishing itself refuses. Step 3's
    // tests are built on this fixture, so they would have been testing something that
    // cannot exist.
    TenantContext::run($this->meridian, function () {
        $template = ProcessCase::factory()->create()->template;

        expect($template->status)->toBe(ProcessTemplate::Published)
            ->and($template->steps)->not->toBeEmpty();
    });
});

it('opens the second version to a new case while the first is retired', function () {
    TenantContext::run($this->meridian, function () {
        $first = meridiansExitDraft();
        $first->publish();

        $second = $first->draftNextVersion();
        $second->publish();

        // What a screen asks for when Chandni resigns: this client's live exit process.
        $live = ProcessTemplate::query()
            ->where('key', 'exit')
            ->where('status', ProcessTemplate::Published)
            ->sole();

        expect($live->getKey())->toBe($second->getKey());
    });
});
