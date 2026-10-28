<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = User::withTrashed()->firstOrNew(['username' => 'admin']);
            if (! $admin->exists) {
                $admin->fill(['name' => 'Administrator Nayaka MCU', 'email' => 'admin@nayaka.co.id']);
            }
            if ($admin->trashed()) {
                $admin->restore();
            }
            $password = (string) config('mcu.seed_password', 'password');
            if (! $admin->password || ! Hash::check($password, $admin->password)) {
                $admin->password = Hash::make($password);
            }
            $admin->role = 'admin';
            $admin->save();

            $this->call(McuTemplateMasterSeeder::class);
        });
    }
}
