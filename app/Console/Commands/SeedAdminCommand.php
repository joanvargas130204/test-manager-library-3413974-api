<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SeedAdminCommand extends Command
{
    protected $signature = 'app:seed-admin';
    protected $description = 'Provision initial admin user from environment variables';

    public function handle(): int
    {
        $username = strtolower(trim(env('ADMIN_USERNAME', 'admin')));
        $password = env('ADMIN_PASSWORD', 'AdminPassword123!');

        $user = User::where('username', $username)->first();

        if (!$user) {
            $user = new User();
            $user->username = $username;
            $user->password = $password;
            $user->role = 'admin';
            $user->save();
            $this->info("Initial admin '{$username}' created successfully.");
        } else {
            $this->info("Admin '{$username}' already exists.");
        }

        return Command::SUCCESS;
    }
}
