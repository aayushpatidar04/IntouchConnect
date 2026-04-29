<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AuditService;
use App\Services\GatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function __construct(private GatewayService $gateway) {}

    private function authorizeSuperAdmin(): void
    {
        if (! auth()->user()->isSuperAdmin()) {
            abort(403, 'Only super-admin can access this area.');
        }
    }

    // ── Companies List ────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorizeSuperAdmin();

        $companies = Company::withCount('users')
            ->with([
                'whatsappSessions' => fn($q) => $q->latest()->limit(1),
                'admins'           => fn($q) => $q->select('id', 'name', 'email', 'company_id'),
            ])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('slug', 'like', "%{$request->search}%")
            )
            ->when($request->status === 'active',   fn($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn($company) => [
                'id'             => $company->id,
                'name'           => $company->name,
                'slug'           => $company->slug,
                'is_active'      => $company->is_active,
                'users_count'    => $company->users_count,
                'session_id'     => $company->session_id,
                'session_status' => $company->whatsappSessions->first()?->status ?? 'disconnected',
                'session_phone'  => $company->whatsappSessions->first()?->phone,
                'admin_name'     => $company->admins->first()?->name,
                'admin_email'    => $company->admins->first()?->email,
                'created_at'     => $company->created_at,
            ]);

        return Inertia::render('SuperAdmin/Companies/Index', [
            'companies' => $companies,
            'filters'   => $request->only(['search', 'status']),
        ]);
    }

    // ── Create Company + First Admin ──────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'slug'           => 'required|string|max:50|unique:companies,slug|regex:/^[a-z0-9\-]+$/',
            'admin_name'     => 'required|string|max:191',
            'admin_email'    => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8|confirmed',
            'admin_phone'    => 'nullable|string|max:20',
        ]);

        // Create company
        $company = Company::create([
            'name'      => $data['name'],
            'slug'      => $data['slug'],
            'is_active' => true,
        ]);

        // Create company's first admin user
        $admin = User::create([
            'company_id' => $company->id,
            'name'       => $data['admin_name'],
            'email'      => $data['admin_email'],
            'password'   => Hash::make($data['admin_password']),
            'phone'      => $data['admin_phone'] ?? null,
            'is_active'  => true,
        ]);
        $admin->assignRole('admin');

        // Provision WhatsApp session on gateway
        try {
            $this->gateway->createSession($company->session_id);
        } catch (\Throwable $e) {
            session()->flash('warning', "Company created but gateway session failed: {$e->getMessage()}. You can retry from the company detail page.");
        }

        AuditService::log('superadmin.company_created', $company, [], [
            'company_name' => $company->name,
            'admin_email'  => $admin->email,
        ]);

        return redirect()
            ->route('superadmin.companies.show', $company)
            ->with('success', "Company \"{$company->name}\" created. Admin: {$admin->email}");
    }

    // ── Company Detail ────────────────────────────────────────────────────────

    public function show(Company $company): Response
    {
        $this->authorizeSuperAdmin();

        $company->load([
            'users.roles',
            'whatsappSessions' => fn($q) => $q->latest()->limit(1),
        ]);

        // Live gateway status for this session
        $gatewayStatus = [];
        try {
            $gatewayStatus = $this->gateway->getStatus($company->session_id);
        } catch (\Throwable) {}

        // Users broken down by role — no customer data exposed
        // Sort order defined in PHP (not SQL) so it works on both SQLite (dev) and MySQL (prod)
        $roleOrder = ['admin' => 0, 'executive' => 1, 'auditor' => 2];

        $users = User::where('company_id', $company->id)
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->sortBy(fn($user) => $roleOrder[$user->roles->first()?->name] ?? 99)
            ->values()
            ->map(fn($user) => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'phone'        => $user->phone,
                'role'         => $user->roles->first()?->name,
                'is_active'    => $user->is_active,
                'last_seen_at' => $user->last_seen_at,
                'created_at'   => $user->created_at,
            ]);

        return Inertia::render('SuperAdmin/Companies/Show', [
            'company'       => [
                'id'             => $company->id,
                'name'           => $company->name,
                'slug'           => $company->slug,
                'is_active'      => $company->is_active,
                'session_id'     => $company->session_id,
                'session_status' => $company->whatsappSessions->first()?->status ?? 'disconnected',
                'session_phone'  => $company->whatsappSessions->first()?->phone,
                'created_at'     => $company->created_at,
            ],
            'users'         => $users,
            'gatewayStatus' => $gatewayStatus,
        ]);
    }

    // ── Update Company ────────────────────────────────────────────────────────

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'      => 'required|string|max:191',
            'is_active' => 'boolean',
        ]);

        $old = $company->only(['name', 'is_active']);
        $company->update($data);

        AuditService::log('superadmin.company_updated', $company, $old, $data);

        return back()->with('success', 'Company updated.');
    }

    // ── Delete Company ────────────────────────────────────────────────────────

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        // Remove gateway session first
        try {
            $this->gateway->deleteSession($company->session_id);
        } catch (\Throwable) {}

        AuditService::log('superadmin.company_deleted', $company, $company->toArray(), []);
        $company->delete(); // soft delete

        return redirect()
            ->route('superadmin.companies.index')
            ->with('success', "Company \"{$company->name}\" deleted.");
    }

    // ── Provision / Re-provision Gateway Session ──────────────────────────────

    public function provisionSession(Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        try {
            $this->gateway->createSession($company->session_id);
            return back()->with('success', 'Gateway session provisioned. A QR code will appear on the gateway dashboard shortly.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to provision session: ' . $e->getMessage());
        }
    }

    // ── Logout / Reset WhatsApp Session ──────────────────────────────────────

    public function logoutSession(Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        try {
            $this->gateway->logout($company->session_id);
            AuditService::log('superadmin.session_reset', $company);
            return back()->with('success', 'WhatsApp session reset. A new QR code will be generated.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to reset session: ' . $e->getMessage());
        }
    }

    // ── Company Admin Management ──────────────────────────────────────────────
    // Super-admin can add/update/remove the admin user of any company.
    // They cannot manage executives — that's the company admin's job.

    public function storeAdmin(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'     => 'required|string|max:191',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone'    => 'nullable|string|max:20',
        ]);

        $admin = User::create([
            'company_id' => $company->id,
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'phone'      => $data['phone'] ?? null,
            'is_active'  => true,
        ]);
        $admin->assignRole('admin');

        AuditService::log('superadmin.admin_created', $admin, [], [
            'company_id' => $company->id,
            'email'      => $admin->email,
        ]);

        return back()->with('success', "Admin {$admin->email} added to {$company->name}.");
    }

    public function updateAdmin(Request $request, Company $company, User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        // Make sure this user actually belongs to this company
        if ($user->company_id !== $company->id) abort(403);

        $data = $request->validate([
            'name'      => 'required|string|max:191',
            'email'     => "required|email|unique:users,email,{$user->id}",
            'phone'     => 'nullable|string|max:20',
            'is_active' => 'boolean',
            'password'  => 'nullable|string|min:8|confirmed',
        ]);

        $old = $user->only(['name', 'email', 'phone', 'is_active']);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        AuditService::log('superadmin.admin_updated', $user, $old, $data);

        return back()->with('success', 'Admin updated.');
    }

    public function destroyAdmin(Company $company, User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        if ($user->company_id !== $company->id) abort(403);

        // Prevent removing the only admin of a company
        $adminCount = User::where('company_id', $company->id)
            ->whereHas('roles', fn($q) => $q->where('name', 'admin'))
            ->count();

        if ($adminCount <= 1) {
            return back()->with('error', 'Cannot remove the only admin of a company. Add another admin first.');
        }

        AuditService::log('superadmin.admin_deleted', $user);
        $user->delete();

        return back()->with('success', 'Admin removed.');
    }

    // ── Toggle Company Active Status ──────────────────────────────────────────

    public function toggleActive(Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $company->update(['is_active' => ! $company->is_active]);
        $status = $company->is_active ? 'activated' : 'deactivated';

        AuditService::log('superadmin.company_toggled', $company, [], ['is_active' => $company->is_active]);

        return back()->with('success', "Company \"{$company->name}\" {$status}.");
    }
}