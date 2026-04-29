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
        Role::firstOrCreate(['name' => 'super_admin']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'executive']);
        Role::firstOrCreate(['name' => 'auditor']);

        // ── Super Admin (no company_id — platform owner) ──────────────────────
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
            ['email' => 'admin@crm.test'],
            [
                'company_id' => $company1->id,
                'name'       => 'Admin User',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        if (! $admin1->company_id) $admin1->update(['company_id' => $company1->id]);
        $admin1->syncRoles(['admin']);

        $exec1 = User::firstOrCreate(
            ['email' => 'sarah@crm.test'],
            [
                'company_id' => $company1->id,
                'name'       => 'Sarah Johnson',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        if (! $exec1->company_id) $exec1->update(['company_id' => $company1->id]);
        $exec1->syncRoles(['executive']);

        // ── Demo Company 2: Beta Ltd ──────────────────────────────────────────
        $company2 = Company::firstOrCreate(
            ['slug' => 'beta-ltd'],
            ['name' => 'Beta Ltd', 'is_active' => true]
        );

        $exec2 = User::firstOrCreate(
            ['email' => 'raj@crm.test'],
            [
                'company_id' => $company2->id,
                'name'       => 'Raj Patel',
                'password'   => Hash::make('password'),
                'is_active'  => true,
            ]
        );
        if (! $exec2->company_id) $exec2->update(['company_id' => $company2->id]);
        $exec2->syncRoles(['executive']);

        // ── Sample customers ──────────────────────────────────────────────────
        if (Customer::count() === 0) {
            Customer::factory()->count(5)->create([
                'company_id'  => $company1->id,
                'assigned_to' => $exec1->id,
            ]);
            Customer::factory()->count(5)->create([
                'company_id'  => $company2->id,
                'assigned_to' => $exec2->id,
            ]);
        }

        $this->command->info('');
        $this->command->info('=== Seed complete ===');
        $this->command->info('Super Admin  : superadmin@intouchconnect.com  /  SuperAdmin@123!');
        $this->command->info('Admin (Acme) : admin@crm.test                 /  password');
        $this->command->info('Exec  (Acme) : sarah@crm.test                 /  password');
        $this->command->info('Exec  (Beta) : raj@crm.test                   /  password');
        $this->command->info('');
        $this->command->info('Run: php artisan db:seed (safe to re-run — uses firstOrCreate)');
    }
}