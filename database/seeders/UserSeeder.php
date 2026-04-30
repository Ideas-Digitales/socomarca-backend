<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Faker\Factory as Faker;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $faker = Faker::create();

        $fakeusers = $this->getFakeUsers();
        $roles = ['superadmin', 'admin', 'supervisor', 'editor'];

        foreach ($fakeusers as $i => $fu) {
            $user = User::create([
                'name' => $fu->name,
                'email' => $fu->email,
                'password' => Hash::make('password'),
                'phone' => $faker->numberBetween(777777777, 999999999),
                'rut' => $fu->rut,
                'business_name' => $faker->company(),
                'is_active' => true,

            ]);

            if ($i < count($roles)) {
                $user->assignRole($roles[$i]);
            } else {
                $user->assignRole('customer');
            }

        }

        //Usuario personalizado
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@admin.com',
            'password' => Hash::make('password'),
            'phone' => '1234567890',
            'rut' => '22375589-5',
            'business_name' => 'Admin',
            'is_active' => true,
            'last_login' => now(),
        ]);
        $admin->assignRole('admin');
    }

    public function getFakeUsers()
    {
        $usersJsonFile = Storage::disk('local')->get('fake_seed_data/users.json');
        $users = json_decode($usersJsonFile);
        return $users;
    }
}
