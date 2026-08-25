<?php

namespace App\Console\Commands;

use App\Exceptions\ProcessRefused;
use App\Models\ProcessCase;
use App\Models\Tenant;
use App\Process\StepLink;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Send a fresh link for a step somebody with no account has to answer, and print it.
 *
 * The link is capped at a couple of days on purpose, so a demo left overnight has a dead
 * one in it. This is how another is asked for from this side; the person holding the dead
 * link asks for their own from the page it sends them to.
 *
 * It is also the exact call module 06's scheduled pass will make when such a step opens.
 * What lives here is nothing but the rule for issuing one — the schedule belongs there and
 * this module registers none.
 */
class SendStepLink extends Command
{
    protected $signature = 'step:link
                            {case : Which case, by its number}
                            {sequence : Which step of it}
                            {--tenant= : The client company, by subdomain}';

    protected $description = 'Send a link for a step answered by somebody with no account';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->components->error('No client company has the subdomain ['.$this->option('tenant').'].');

            return self::FAILURE;
        }

        return TenantContext::run($tenant, function (): int {
            $case = ProcessCase::query()->find($this->argument('case'));

            if ($case === null) {
                $this->components->error('That company has no case numbered ['.$this->argument('case').'].');

                return self::FAILURE;
            }

            try {
                $address = (new StepLink)->issue($case, (int) $this->argument('sequence'));
            } catch (ProcessRefused $refused) {
                $this->components->error($refused->getMessage());

                return self::FAILURE;
            }

            $this->components->info('Sent. It works for the next '.StepLink::LastsHours.' hours.');
            $this->line($address);

            return self::SUCCESS;
        });
    }
}
