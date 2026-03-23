<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

class SardegnaSentieriSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Administrator', 'Editor', 'Validator', 'Guest'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $forestasUser = User::firstOrCreate(
            ['email' => 'forestas@webmapp.it'],
            [
                'name' => 'forestas',
                'password' => Hash::make(Str::random(32)),
            ]
        );
        $forestasUser->syncRoles([Role::findByName('Editor', 'web')]);

        $admin = User::firstOrCreate(
            ['email' => 'team@webmapp.it'],
            [
                'name' => 'Admin Team',
                'password' => Hash::make('webmapp123'),
            ]
        );
        $admin->syncRoles([Role::findByName('Administrator', 'web')]);

        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;

        if (! App::query()->whereKey($appId)->exists()) {
            App::withoutEvents(fn () => App::query()->create([
                'id' => $appId,
                'name' => 'Forestas',
                'sku' => 'it.webmapp.forestas',
                'customer_name' => 'forestas',
                'user_id' => $forestasUser->id,
            ]));
        }
    }
}
