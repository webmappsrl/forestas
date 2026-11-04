<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TaxonomyTermSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->call('sardegnasentieri:configure-taxonomy-terms');
    }
}
