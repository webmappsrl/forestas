<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;

class SardegnaSentieriSeeder extends Seeder
{
    public function run(): void
    {
        // App
        App::firstOrCreate(
            ['sku' => 'it.webmapp.sardegnasentieri'],
            [
                'name'          => 'Sardegna Sentieri',
                'customer_name' => 'forestas',
            ]
        );

        // User
        $user = User::firstOrCreate(
            ['email' => 'forestas@webmapp.it'],
            [
                'name'     => 'forestas',
                'password' => Hash::make(Str::random(32)),
            ]
        );
        $user->assignRole('Editor');
    }
}
