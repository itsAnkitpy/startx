<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(MeridianSeeder::class);

        // A second client company, whose exit asks for something else entirely. Two
        // companies is what actually shows that a client's form is rows rather than a
        // schema — one of them proves nothing.
        $this->call(VertexSeeder::class);
    }
}
