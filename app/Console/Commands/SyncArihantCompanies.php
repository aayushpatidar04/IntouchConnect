<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\Company;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Sync Arihant Bitrix24 departments & agents into companies/users.
 *
 * KEY RULE: Agents with multiple DepartmentIds are assigned to the
 * DEEPEST (most specific) department only. This prevents double-counting
 * across parent/child departments.
 *
 * Example: Agent with DepartmentIds [21, 25] → assigned to 25 (Priyank's Team)
 *          NOT counted in 21 (B2C Revenue).
 *
 * Usage:
 *   php artisan sync:arihant-companies
 *   php artisan sync:arihant-companies --fresh
 */
class SyncArihantCompanies extends Command
{
    protected $signature = 'sync:arihant-companies {--fresh : Delete all previously synced companies & users}';
    protected $description = 'Sync Arihant Bitrix24 departments & agents into companies and users';

    private const MAX_EXECUTIVES = 4;

    private const DEPT_API_URL = 'https://arihantapicore.arihantcapital.com/V1/bitrix24/Getdepartments';
    private const AGENT_API_URL = 'https://arihantapicore.arihantcapital.com/V1/bitrix24/GetAgents';
    private const API_USERNAME = 'Arihant';
    private const API_PASSWORD = 'Arihant@2021';

    private array $deptMap = [];
    private array $agentsByDept = [];   // deptId => agents (after dedup - deepest only)
    private array $agentPrimaryDept = []; // agentId => primary (deepest) deptId
    private array $agentMap = [];
    private array $companyMap = [];
    private array $userMap = [];
    private array $neededDepts = [];
    private array $childrenMap = [];

    public function handle(): int
    {
        $this->info('🚀 Starting Arihant Bitrix24 department & agent sync...');

        if ($this->option('fresh')) {
            $this->warn('Deleting ALL previously synced Arihant companies & users...');
            $this->deletePreviouslySynced();
        }

        $this->info('Fetching from APIs...');
        $departments = $this->fetchDepartments();
        $agents = $this->fetchAgents();

        $this->info("Loaded " . count($departments) . " departments & " . count($agents) . " agents.");

        $this->buildMaps($departments, $agents);
        $this->buildChildrenMap($departments);
        $this->determinePrimaryDepartments(); // NEW: assign each agent to deepest dept
        $this->determineNeededDepartments();

        $this->info("Will create companies for " . count($this->neededDepts) . " departments.");

        $this->createCompanies($departments);
        $this->createUsers($agents);
        $this->assignAdmins($departments);
        $this->assignExecutives($agents);

        $this->info('✅ Sync completed!');
        return self::SUCCESS;
    }

    private function fetchDepartments(): array
    {
        $response = Http::withBasicAuth(self::API_USERNAME, self::API_PASSWORD)
            ->get(self::DEPT_API_URL);

        if (!$response->successful()) {
            $this->error("Failed departments. HTTP {$response->status()}");
            exit(self::FAILURE);
        }

        $data = $response->json();
        if (isset($data['data']) && is_array($data['data'])) return $data['data'];
        if (isset($data['result']) && is_array($data['result'])) return $data['result'];
        return is_array($data) ? $data : [];
    }

    private function fetchAgents(): array
    {
        $response = Http::withBasicAuth(self::API_USERNAME, self::API_PASSWORD)
            ->get(self::AGENT_API_URL);

        if (!$response->successful()) {
            $this->error("Failed agents. HTTP {$response->status()}");
            exit(self::FAILURE);
        }

        $data = $response->json();
        if (isset($data['data']) && is_array($data['data'])) return $data['data'];
        if (isset($data['result']) && is_array($data['result'])) return $data['result'];
        return is_array($data) ? $data : [];
    }

    private function buildMaps(array $departments, array $agents): void
    {
        foreach ($departments as $dept) {
            $this->deptMap[$dept['Id']] = $dept;
        }
        foreach ($agents as $agent) {
            $this->agentMap[$agent['Id']] = $agent;
        }
    }

    private function buildChildrenMap(array $departments): void
    {
        foreach ($departments as $dept) {
            $parentId = $dept['ParentId'];
            if ($parentId > 0) {
                if (!isset($this->childrenMap[$parentId])) {
                    $this->childrenMap[$parentId] = [];
                }
                $this->childrenMap[$parentId][] = $dept['Id'];
            }
        }
    }

    /**
     * Calculate depth of a department (distance from root).
     * Root = 1, direct child of root = 2, etc.
     */
    private function getDeptDepth(int $deptId): int
    {
        $depth = 0;
        $current = $deptId;
        while ($current > 0 && isset($this->deptMap[$current])) {
            $depth++;
            $current = $this->deptMap[$current]['ParentId'];
        }
        return $depth;
    }

    /**
     * NEW: For each agent with multiple DepartmentIds, determine their PRIMARY
     * (deepest/most specific) department. Agents are only counted in this dept.
     *
     * Example: Agent with [21, 25] → primary = 25 (Priyank's Team, depth 4)
     *          NOT counted in 21 (B2C Revenue, depth 3).
     */
    private function determinePrimaryDepartments(): void
    {
        foreach ($this->agentMap as $agentId => $agent) {
            $deptIds = $agent['DepartmentIds'];

            if (count($deptIds) === 1) {
                // Only one dept — this is their primary
                $primaryDeptId = $deptIds[0];
            } else {
                // Multiple depts — find the deepest one (highest depth)
                $primaryDeptId = $deptIds[0];
                $maxDepth = $this->getDeptDepth($primaryDeptId);

                foreach ($deptIds as $deptId) {
                    $depth = $this->getDeptDepth($deptId);
                    if ($depth > $maxDepth) {
                        $maxDepth = $depth;
                        $primaryDeptId = $deptId;
                    }
                }
            }

            $this->agentPrimaryDept[$agentId] = $primaryDeptId;

            // Add agent to their primary dept only
            if (!isset($this->agentsByDept[$primaryDeptId])) {
                $this->agentsByDept[$primaryDeptId] = [];
            }
            $this->agentsByDept[$primaryDeptId][] = $agent;

            if (count($deptIds) > 1) {
                $this->info("  Agent {$agent['Name']} (IDs: " . implode(',', $deptIds) . ") → primary dept: {$primaryDeptId} ({$this->deptMap[$primaryDeptId]['Name']})");
            }
        }
    }

    private function determineNeededDepartments(): void
    {
        $needed = [];
        $markBranch = function (int $deptId) use (&$markBranch, &$needed) {
            if (!isset($this->deptMap[$deptId])) return;
            $needed[$deptId] = true;
            $parentId = $this->deptMap[$deptId]['ParentId'];
            if ($parentId > 0) {
                $markBranch($parentId);
            }
        };

        foreach ($this->agentsByDept as $deptId => $agents) {
            if (count($agents) > 0) {
                $markBranch($deptId);
            }
        }
        foreach ($this->deptMap as $deptId => $dept) {
            if ($dept['HeadUserId'] > 0) {
                $markBranch($deptId);
            }
        }
        $needed[1] = true;
        $this->neededDepts = array_keys($needed);
    }

    private function hasLiveChildren(int $deptId): bool
    {
        $children = $this->childrenMap[$deptId] ?? [];
        foreach ($children as $childId) {
            if (in_array($childId, $this->neededDepts)) {
                $child = $this->deptMap[$childId];
                $childAgents = count($this->agentsByDept[$childId] ?? []);
                $childHasHead = $child['HeadUserId'] > 0;
                if ($childAgents > 0 || $childHasHead) {
                    return true;
                }
                if ($this->hasLiveChildren($childId)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function createCompanies(array $departments): void
    {
        usort($departments, fn($a, $b) => [$a['Sort'], $a['Id']] <=> [$b['Sort'], $b['Id']]);

        $processed = [];
        $queue = [1];

        while (!empty($queue)) {
            $deptId = array_shift($queue);
            if (isset($processed[$deptId])) continue;
            $processed[$deptId] = true;

            if (!in_array($deptId, $this->neededDepts)) {
                foreach ($departments as $d) {
                    if ($d['ParentId'] === $deptId) {
                        $queue[] = $d['Id'];
                    }
                }
                continue;
            }

            $dept = $this->deptMap[$deptId];
            $agents = $this->agentsByDept[$deptId] ?? [];
            $agentCount = count($agents);
            $hasHeadUser = $dept['HeadUserId'] > 0;
            $hasLiveChildren = $this->hasLiveChildren($deptId);

            if ($agentCount === 0 && !$hasHeadUser && !$hasLiveChildren) {
                $this->info("  Skipping: {$dept['Name']} (ID: {$deptId}) — empty");
                foreach ($departments as $d) {
                    if ($d['ParentId'] === $deptId) {
                        $queue[] = $d['Id'];
                    }
                }
                continue;
            }

            $parentCompanyId = null;
            if ($dept['ParentId'] > 0 && isset($this->companyMap[$dept['ParentId']])) {
                $parentCompanyId = $this->companyMap[$dept['ParentId']][0] ?? null;
            }

            $shortName = trim($dept['Name']);
            $hierarchyPath = $this->buildHierarchyPath($deptId);
            $chunks = $agentCount > 0 ? (int) ceil($agentCount / self::MAX_EXECUTIVES) : 1;

            $this->companyMap[$deptId] = [];

            for ($i = 1; $i <= $chunks; $i++) {
                $name = $chunks > 1 ? "{$shortName} {$i}" : $shortName;
                $slug = Str::slug($name . '-' . $deptId . '-' . $i);

                $company = Company::updateOrCreate(
		    ['name' => $name],
		    [
                    	'slug' => $slug,
                    	'external_department_id' => (string) $deptId,
                    	'hierarchy_path' => $hierarchyPath,
                    	'parent_company_id' => $parentCompanyId,
                    	'is_active' => true,
                    ]);

                $this->companyMap[$deptId][] = $company->id;
                $type = ($agentCount === 0 && !$hasHeadUser) ? 'container' : 'active';
                $this->info("  Created [{$type}]: {$name} (ID: {$company->id}, {$agentCount} agents)");
            }

            foreach ($departments as $d) {
                if ($d['ParentId'] === $deptId) {
                    $queue[] = $d['Id'];
                }
            }
        }
    }

    private function buildHierarchyPath(int $deptId): string
    {
        $parts = [];
        $current = $deptId;
        while ($current > 0 && isset($this->deptMap[$current])) {
            $parts[] = trim($this->deptMap[$current]['Name']);
            $current = $this->deptMap[$current]['ParentId'];
        }
        return implode(' -> ', array_reverse($parts));
    }

    private function createUsers(array $agents): void
    {
        $defaultPassword = Hash::make('Arihant@123');

        foreach ($agents as $agent) {
            $email = $agent['Email'] ?? null;
            if (empty($email)) {
                $this->warn("Agent {$agent['Id']} ({$agent['Name']}) has no email, skipping.");
                continue;
            }

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $agent['Name'],
                    'phone' => $agent['Mobile'] ?: null,
                    'password' => $defaultPassword,
                    'is_active' => $agent['Active'],
                    'company_id' => null,
		    'bitrix_user_id' => $agent['Id'],
                ]
            );

            $this->userMap[$agent['Id']] = $user->id;
            $this->info("  User: {$agent['Name']} <{$email}> (ID: {$user->id})");
        }
    }

    private function assignAdmins(array $departments): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        foreach ($departments as $dept) {
            $deptId = $dept['Id'];
            $headId = $dept['HeadUserId'];

            if ($headId <= 0 || !in_array($deptId, $this->neededDepts)) {
                continue;
            }
            if (!isset($this->companyMap[$deptId]) || empty($this->companyMap[$deptId])) {
                continue;
            }
            if (!isset($this->userMap[$headId])) {
                $this->warn("Head user {$headId} not in agents for dept {$deptId} — skipping");
                continue;
            }

            $userId = $this->userMap[$headId];
            $user = User::find($userId);
            if (!$user) continue;

            if (!$user->hasRole('admin')) {
                $user->assignRole($adminRole);
            }

            foreach ($this->companyMap[$deptId] as $companyId) {
                DB::table('user_company_access')->updateOrInsert(
                    ['user_id' => $userId, 'company_id' => $companyId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
                $this->info("  Admin: {$user->name} → Company {$companyId}");
            }
        }
    }

    private function assignExecutives(array $agents): void
    {
        $executiveRole = Role::firstOrCreate(['name' => 'executive', 'guard_name' => 'web']);

        foreach ($agents as $agent) {
            $userId = $this->userMap[$agent['Id']] ?? null;
            if (!$userId) continue;

            $user = User::find($userId);
            if (!$user) continue;

            if (!$user->hasRole('admin') && !$user->hasRole('executive')) {
                $user->assignRole($executiveRole);
            }

            // Assign to the PRIMARY (deepest) department's company only
            $primaryDeptId = $this->agentPrimaryDept[$agent['Id']] ?? null;
            if (!$primaryDeptId) {
                continue;
            }

            if (!in_array($primaryDeptId, $this->neededDepts)) {
                continue;
            }
            if (!isset($this->companyMap[$primaryDeptId]) || empty($this->companyMap[$primaryDeptId])) {
                continue;
            }

            $companyIds = $this->companyMap[$primaryDeptId];
            $deptAgents = $this->agentsByDept[$primaryDeptId] ?? [];
            $agentCount = count($deptAgents);

            if ($agentCount <= self::MAX_EXECUTIVES) {
                $targetCompanyId = $companyIds[0];
                $this->assignToCompany($user, $targetCompanyId);
            } else {
                $agentIndex = -1;
                foreach ($deptAgents as $idx => $da) {
                    if ($da['Id'] === $agent['Id']) {
                        $agentIndex = $idx;
                        break;
                    }
                }
                if ($agentIndex === -1) continue;

                $chunkIndex = floor($agentIndex / self::MAX_EXECUTIVES);
                $targetCompanyId = $companyIds[$chunkIndex] ?? $companyIds[count($companyIds) - 1];
                $this->assignToCompany($user, $targetCompanyId);
            }
        }
    }

    private function assignToCompany(User $user, int $companyId): void
    {
        if (is_null($user->company_id)) {
            $user->update(['company_id' => $companyId]);
        }
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $user->id, 'company_id' => $companyId],
            ['created_at' => now(), 'updated_at' => now()]
        );
        $company = Company::find($companyId);
        $this->info("  Exec: {$user->name} → {$company?->name} ({$companyId})");
    }

    private function deletePreviouslySynced(): void
    {
        $syncedCompanyIds = Company::whereNotNull('external_department_id')
            ->orWhere(function ($q) {
                $q->where('slug', 'like', 'arihant%')
                  ->orWhere('slug', 'like', 'b2b%')
                  ->orWhere('slug', 'like', 'b2c%')
                  ->orWhere('slug', 'like', 'pan-india%')
                  ->orWhere('slug', 'like', 'atul%')
                  ->orWhere('slug', 'like', 'aife%')
                  ->orWhere('slug', 'like', 'namo%')
                  ->orWhere('slug', 'like', 'shivani%')
                  ->orWhere('slug', 'like', 'fsm%')
                  ->orWhere('slug', 'like', 'algo%')
                  ->orWhere('slug', 'like', 'wealth%');
            })
            ->pluck('id')
            ->toArray();

        $arihantEmails = User::where('email', 'like', '%@arihantcapital.com')
            ->orWhere('email', 'like', '%@arihantplus.com')
            ->orWhere('email', 'like', '%@questglt.com')
            ->orWhere('email', 'like', '%@questglt.org')
            ->pluck('id')
            ->toArray();

        $linkedCompanyIds = DB::table('user_company_access')
            ->whereIn('user_id', $arihantEmails)
            ->pluck('company_id')
            ->unique()
            ->toArray();

        $allCompanyIds = array_unique(array_merge($syncedCompanyIds, $linkedCompanyIds));

        if (empty($allCompanyIds)) {
            $this->info('No previously synced companies found.');
            return;
        }

        $this->warn("Found " . count($allCompanyIds) . " companies to delete.");

        $syncedUserIds = DB::table('user_company_access')
            ->whereIn('company_id', $allCompanyIds)
            ->pluck('user_id')
            ->unique()
            ->toArray();

        $arihantUserIds = User::whereIn('id', $syncedUserIds)
            ->where(function ($q) {
                $q->where('email', 'like', '%@arihantcapital.com')
                  ->orWhere('email', 'like', '%@arihantplus.com')
                  ->orWhere('email', 'like', '%@questglt.com')
                  ->orWhere('email', 'like', '%@questglt.org');
            })
            ->pluck('id')
            ->toArray();

        $this->warn("Found " . count($arihantUserIds) . " users to delete.");

        DB::beginTransaction();

        try {
            DB::table('user_company_access')
                ->whereIn('company_id', $allCompanyIds)
                ->delete();

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $arihantUserIds)
                ->delete();

            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->whereIn('model_id', $arihantUserIds)
                ->delete();

            if (!empty($arihantUserIds)) {
                User::whereIn('id', $arihantUserIds)->delete();
            }

            $companiesToDelete = Company::whereIn('id', $allCompanyIds)
                ->orderByDesc('id')
                ->get();

            foreach ($companiesToDelete as $company) {
                Company::where('parent_company_id', $company->id)
                    ->update(['parent_company_id' => null]);
                $company->delete();
            }

            DB::commit();
            $this->info('Cleanup done.');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Cleanup failed: " . $e->getMessage());
            throw $e;
        }
    }
}
