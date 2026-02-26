<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AopSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogCourseCsvSeeder::class,
        ]);
    }
}
