<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserCompanyAccess extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (User::all() as $user) {

            if ($user->company_id) {

                DB::table('user_company_access')->insert([
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}

