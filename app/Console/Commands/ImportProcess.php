<?php

namespace App\Console\Commands;

use App\Exceptions\ProcessRefused;
use App\Models\ProcessStep;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Process\FlatFile;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Type a client's process into a spreadsheet, save it as CSV, and run this.
 *
 * The one command this module's adoption claim rests on: a process arrives as a Word
 * document or a spreadsheet at a kickoff meeting, and the time between that meeting and
 * their process running is what decides whether they stay.
 *
 * **It always writes a draft**, never a live process, so nothing here can touch a
 * running case — which is also why it does no validating of its own. Making the draft
 * live is what checks it, exactly as it checks a process built by hand, and a second
 * copy of those rules living here is how the two would quietly stop agreeing.
 */
class ImportProcess extends Command
{
    protected $signature = 'process:import
                            {file : The CSV file, one row per step}
                            {--tenant= : The client company, by subdomain}
                            {--key= : Which process this is a version of}
                            {--name= : The client\'s own words for it, needed only the first time}
                            {--about= : Who it is about — employee, candidate or none; needed only the first time}';

    protected $description = "Read a client's process out of a flat file as a new draft";

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->components->error('No client company has the subdomain ['.$this->option('tenant').'].');

            return self::FAILURE;
        }

        $key = trim((string) $this->option('key'));

        if ($key === '') {
            $this->components->error('Say which process this is, with --key.');

            return self::FAILURE;
        }

        try {
            // Read before anything is looked up or written, so a file that turns out not
            // to be a process leaves no trace of having been tried.
            $steps = (new FlatFile)->read($this->argument('file'));

            return TenantContext::run($tenant, fn (): int => $this->writeTheDraft($key, $steps));
        } catch (ProcessRefused $refusal) {
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function writeTheDraft(string $key, array $steps): int
    {
        $latest = ProcessTemplate::query()->where('key', $key)->orderByDesc('version')->first();

        // The same rule the edit button carries, for the same reason: two unfinished
        // drafts made live in turn means the second quietly undoes the first. It is also
        // what keeps refusing a whole file cheap — without it, the corrected file would
        // land beside the draft the refused one left behind.
        $unfinished = ProcessTemplate::query()->where('key', $key)->where('status', ProcessTemplate::Draft)->first();

        if ($unfinished !== null) {
            throw ProcessRefused::anUnfinishedDraftAlreadyExists($unfinished->name, $unfinished->version);
        }

        // A process this client already has keeps its own name and its own subject, so a
        // round trip cannot rename it or change who it is about by omission.
        $name = trim((string) ($this->option('name') ?: $latest?->name));
        $about = trim((string) ($this->option('about') ?: $latest?->subject_kind));

        if ($name === '' || $about === '') {
            $this->components->error(
                "This client has no process called [{$key}] yet, so this file needs --name for their own "
                .'words for it and --about for who it is about.'
            );

            return self::FAILURE;
        }

        $draft = new ProcessTemplate(['key' => $key, 'name' => $name, 'subject_kind' => $about]);
        $draft->version = ($latest?->version ?? 0) + 1;
        $draft->status = ProcessTemplate::Draft;
        $draft->save();

        foreach ($steps as $step) {
            ProcessStep::create(['template_id' => $draft->getKey()] + $step);
        }

        $this->components->info(
            "[{$name}] version {$draft->version} is a draft with ".count($steps).' steps in it. '
            .'Publishing it is what checks it.'
        );

        return self::SUCCESS;
    }
}
