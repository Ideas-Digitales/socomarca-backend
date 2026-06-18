<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create';
    protected $description = 'Interactively create a new user';

    public function handle()
    {
        $availableRoles = array_keys(config('authorization.roles', []));

        if (empty($availableRoles)) {
            $this->error('No roles found in config/authorization/roles.php');
            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $rawPassword = $this->secret('Password');
        $phone = $this->ask('Phone', '');
        $rut = $this->ask('RUT');
        $businessName = $this->ask('Business name', $name);
        $role = $this->choice('Role', $availableRoles, 0);

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $rawPassword,
            'rut' => $rut,
            'role' => $role,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'rut' => 'required|string|unique:users,rut',
            'role' => 'required|string|in:' . implode(',', $availableRoles),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $hashedPassword = Hash::make($rawPassword);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $hashedPassword,
            'phone' => $phone,
            'rut' => $rut,
            'business_name' => $businessName,
            'is_active' => true,
        ]);

        $user->assignRole($role);

        $this->newLine();
        $this->info('User created successfully:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->id],
                ['Name', $user->name],
                ['Email', $user->email],
                ['Password (unhashed)', $rawPassword],
                ['Phone', $user->phone ?: '(empty)'],
                ['RUT', $user->rut],
                ['Business name', $user->business_name],
                ['Active', $user->is_active ? 'Yes' : 'No'],
                ['Role', $role],
            ]
        );

        return self::SUCCESS;
    }
}
