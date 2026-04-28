<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ─────────────────────────────────────────────────────────────
        // super_admin — owns the SaaS platform, manages all companies
        // admin       — company-level admin, manages their own company's users
        // executive   — handles customer conversations
        // auditor     — read-only access to their company's data
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'executive']);
        Role::firstOrCreate(['name' => 'auditor']);

        // ── Super Admin (no company) ──────────────────────────────────────────
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@intouchconnect.com'],
            [
                'company_id' => null,
                'name'       => 'Super Admin',
                'password'   => Hash::make('SuperAdmin@123!'),
                'is_active'  => true,
            ]
        );
        $superAdmin->syncRoles(['super_admin']);

        // ── Demo Company 1: Acme Corp ─────────────────────────────────────────
        $company1 = Company::firstOrCreate(
            ['slug' => 'acme-corp'],
            ['name' => 'Acme Corp', 'is_active' => true]
        );

        $admin1 = User::firstOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'company_id' => $company1->id,
                'name'       => 'Acme Admin',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        $admin1->syncRoles(['admin']);

        $exec1 = User::firstOrCreate(
            ['email' => 'sarah@acme.test'],
            [
                'company_id' => $company1->id,
                'name'       => 'Sarah Johnson',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        $exec1->syncRoles(['executive']);

        // ── Demo Company 2: Beta Ltd ──────────────────────────────────────────
        $company2 = Company::firstOrCreate(
            ['slug' => 'beta-ltd'],
            ['name' => 'Beta Ltd', 'is_active' => true]
        );

        $admin2 = User::firstOrCreate(
            ['email' => 'admin@beta.test'],
            [
                'company_id' => $company2->id,
                'name'       => 'Beta Admin',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        $admin2->syncRoles(['admin']);

        $exec2 = User::firstOrCreate(
            ['email' => 'raj@beta.test'],
            [
                'company_id' => $company2->id,
                'name'       => 'Raj Patel',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        $exec2->syncRoles(['executive']);

        // ── Sample customers ──────────────────────────────────────────────────
        Customer::factory()->count(5)->create([
            'company_id'  => $company1->id,
            'assigned_to' => $exec1->id,
        ]);
        Customer::factory()->count(5)->create([
            'company_id'  => $company2->id,
            'assigned_to' => $exec2->id,
        ]);

        $this->command->info('');
        $this->command->info('=== Seed complete ===');
        $this->command->info('Super Admin  : superadmin@intouchconnect.com / SuperAdmin@123!');
        $this->command->info('Acme Admin   : admin@acme.test / password');
        $this->command->info('Acme Exec    : sarah@acme.test / password');
        $this->command->info('Beta Admin   : admin@beta.test / password');
        $this->command->info('Beta Exec    : raj@beta.test   / password');
        $this->command->info('');
        $this->command->info('Gateway session IDs: "acme-corp", "beta-ltd"');
        $this->command->info('Make sure these exist in gateway auth_info/ folder (or POST /session/create)');
    }
}