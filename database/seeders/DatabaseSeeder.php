<?php

// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create default organization
        $organization = Organization::create([
            'name' => 'Default Organization',
            'slug' => 'default-org',
            'description' => 'Default organization for testing',
            'plan' => 'professional',
            'is_active' => true,
        ]);

        // Create admin user
        User::create([
            'organization_id' => $organization->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create manager user
        User::create([
            'organization_id' => $organization->id,
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        // Create team member user
        User::create([
            'organization_id' => $organization->id,
            'name' => 'Team Member',
            'email' => 'member@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'role' => 'team_member',
            'is_active' => true,
        ]);

        // Create test user
        User::create([
            'organization_id' => $organization->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'role' => 'team_member',
            'is_active' => true,
        ]);

        $this->command->info('Database seeded successfully!');
        $this->command->info('');
        $this->command->info('Test Credentials:');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('Admin:        admin@example.com / password');
        $this->command->info('Manager:      manager@example.com / password');
        $this->command->info('Team Member:  member@example.com / password');
        $this->command->info('Test User:    test@example.com / password');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}