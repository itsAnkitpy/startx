<?php

namespace App\Console\Commands;

use App\Models\ProcessTemplate;
use App\Models\Tenant;
use App\Process\FlatFile;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Write a client's process back out as the file it was typed in.
 *
 * It exists so the importer can be tested against itself: a process exported and
 * imported again has to come back as the same process, which is a far harder test than
 * importing a file somebody wrote by hand to pass. It is also how a process moves
 * between two of a client's environments, which module 12 later leans on.
 *
 * The file is not promised back byte for byte and never was — Postgres sorts the keys
 * inside a condition, so the same condition writes out in another order. What is
 * promised is the process.
 */
class ExportProcess extends Command
{
    protected $signature = 'process:export
                            {key : Which process to write out}
                            {--tenant= : The client company, by subdomain}
                            {--file= : Where to write it; left off, it goes to the screen}';

    protected $description = "Write a client's process out as a flat file";

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->first();

        if ($tenant === null) {
            $this->components->error('No client company has the subdomain ['.$this->option('tenant').'].');

            return self::FAILURE;
        }

        // Everything that touches the database stays inside the client company's scope,
        // reading the steps included: a relation loaded outside it is one query away
        // from being answered with nothing, or with somebody else's process.
        return TenantContext::run($tenant, fn (): int => $this->writeOut($this->argument('key')));
    }

    private function writeOut(string $key): int
    {
        $template = ProcessTemplate::query()
            ->where('key', $key)
            // The live version first, because that is the one a client means by "our
            // process". Falling back to the newest is what makes a draft exportable at
            // all, which is the round trip this command exists for.
            ->orderByRaw('case when status = ? then 0 else 1 end', [ProcessTemplate::Published])
            ->orderByDesc('version')
            ->first();

        if ($template === null) {
            $this->components->error("This client has no process called [{$key}].");

            return self::FAILURE;
        }

        $csv = (new FlatFile)->write($template);
        $path = $this->option('file');

        if ($path === null) {
            $this->output->write($csv);

            return self::SUCCESS;
        }

        file_put_contents($path, $csv);

        $this->components->info("[{$template->name}] version {$template->version} written to {$path}.");

        return self::SUCCESS;
    }
}
