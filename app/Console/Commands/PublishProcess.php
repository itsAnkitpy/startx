<?php

namespace App\Console\Commands;

use App\Exceptions\ProcessRefused;
use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Make a client's unfinished process live, or say why it cannot go live.
 *
 * The third of the three commands that carry a client's process from a spreadsheet to a
 * running exit. Importing writes a draft and deliberately checks almost nothing, because
 * half-finished is the normal state of a draft; every check that matters happens here,
 * at the one moment there is still something to do about them and before any case is
 * running on the version.
 *
 * Written 26 August 2026, when the check for a step waiting on an answer no form collects
 * landed and there turned out to be nowhere in the product a person could see it. Until
 * then a process could only be made live from a console, so none of these refusals had
 * ever been read by anybody outside a test. Module 12's editor screen replaces this with
 * a button; the checking behind it is the same and stays where it is.
 */
class PublishProcess extends Command
{
    protected $signature = 'process:publish
                            {key : Which process to make live}
                            {--tenant= : The client company, by subdomain}';

    protected $description = "Make a client's draft process live, or say why it cannot be";

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->components->error('No client company has the subdomain ['.$this->option('tenant').'].');

            return self::FAILURE;
        }

        return TenantContext::run($tenant, fn (): int => $this->makeItLive($this->argument('key')));
    }

    private function makeItLive(string $key): int
    {
        $draft = ProcessTemplate::query()
            ->where('key', $key)
            ->where('status', ProcessTemplate::Draft)
            ->orderByDesc('version')
            ->first();

        if ($draft === null) {
            $this->components->error("This client has no unfinished [{$key}] waiting to go live.");

            return self::FAILURE;
        }

        try {
            $draft->publish();
        } catch (ProcessRefused $refusal) {
            // Every problem at once rather than the first, because a client fixing them
            // one round trip at a time is how a process takes a week to go live.
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "[{$draft->name}] version {$draft->version} is live. Any case already running stays on the "
            .'version it opened on.'
        );

        return self::SUCCESS;
    }
}
