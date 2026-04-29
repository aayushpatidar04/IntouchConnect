<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe "fix-up" migration.
 * Run this if you already ran the earlier migrations and seeder and are hitting:
 *   - "Target class [role] does not exist" (fixed in bootstrap/app.php)
 *   - Missing super_admin role in the roles table
 *   - Users table missing company_id column
 *
 * All operations are guarded so re-running is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Ensure users.company_id exists and is nullable ─────────────────
        // The earlier companies migration adds this, but if it was run before
        // the companies table existed (or was skipped), this guards against that.
        if (! Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('company_id')
                      ->nullable()
                      ->after('id')
                      ->constrained('companies')
                      ->nullOnDelete();
                $table->index('company_id');
            });
        }

        // ── 2. Ensure super_admin role row exists in Spatie roles table ───────
        // Spatie stores roles in the `roles` table. If the seeder hasn't been
        // run yet (or was run before this role was added), insert it now.
        $exists = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->exists();

        if (! $exists) {
            DB::table('roles')->insert([
                'name'       => 'super_admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ── 3. Ensure all other roles exist too ───────────────────────────────
        foreach (['admin', 'executive', 'auditor'] as $role) {
            $exists = DB::table('roles')
                ->where('name', $role)
                ->where('guard_name', 'web')
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'name'       => $role,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove super_admin role (does not remove assigned role records)
        DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->delete();
    }
};