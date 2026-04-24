<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles
        $admin    = Role::firstOrCreate(['name' => 'admin']);
        $exec     = Role::firstOrCreate(['name' => 'executive']);
        $auditor  = Role::firstOrCreate(['name' => 'auditor']);

        // Admin user
        $adminUser = User::factory()->create([
            'name'  => 'Admin User',
            'email' => 'admin@crm.test',
        ]);
        $adminUser->assignRole('admin');

        // Executives
        $exec1 = User::factory()->create(['name' => 'Sarah Johnson', 'email' => 'sarah@crm.test']);
        $exec2 = User::factory()->create(['name' => 'Raj Patel',     'email' => 'raj@crm.test']);
        $exec1->assignRole('executive');
        $exec2->assignRole('executive');

        // Sample customers
        Customer::factory()->count(5)->create(['assigned_to' => $exec1->id]);
        Customer::factory()->count(5)->create(['assigned_to' => $exec2->id]);

        $this->command->info('Seeded: admin@crm.test / password');
        $this->command->info('Seeded: sarah@crm.test / password');
        $this->command->info('Seeded: raj@crm.test / password');
    }
}