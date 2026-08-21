<?php

namespace App\Console\Commands;

use App\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Makes an account for one of SummerHill's own people.
 *
 * A command rather than a seeder on purpose: a seeder would put a real address and a
 * real password into the repository and create them again on every fresh database.
 * This asks when it is run, so nothing is written down. Filament ships
 * `make:filament-user` for the same reason; this is that, pointed at our own table.
 */
class CreatePlatformUser extends Command
{
    protected $signature = 'startx:platform-user
                            {--name= : Their name}
                            {--email= : Their email address}';

    protected $description = "Create an account for one of SummerHill's own people";

    public function handle(): int
    {
        $attributes = [
            'name' => $this->option('name') ?: text('Name', required: true),
            'email' => $this->option('email') ?: text('Email address', required: true),
            'password' => password('Password', required: true),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:platform_users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        PlatformUser::create($attributes);

        $this->components->info($attributes['email'].' can now sign in at '.url('/platform').'.');

        return self::SUCCESS;
    }
}
