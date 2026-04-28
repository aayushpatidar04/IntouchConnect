<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
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

    /**
     * Super-admin only — gate on every method.
     */
    private function authorizeSuperAdmin(): void
    {
        if (! auth()->user()->isSuperAdmin()) {
            abort(403, 'Only super-admin can manage companies.');
        }
    }

    // ── Company CRUD ──────────────────────────────────────────────────────────

    public function index(): Response
    {
        $this->authorizeSuperAdmin();

        $companies = Company::withCount(['users', 'customers'])
            ->with('whatsappSessions')
            ->orderBy('name')
            ->paginate(20);

        return Inertia::render('SuperAdmin/Companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'slug'           => 'required|string|max:50|unique:companies,slug|regex:/^[a-z0-9\-]+$/',
            'admin_name'     => 'required|string|max:191',
            'admin_email'    => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8',
            'admin_phone'    => 'nullable|string|max:20',
        ]);

        // Create company
        $company = Company::create([
            'name'      => $data['name'],
            'slug'      => $data['slug'],
            'is_active' => true,
        ]);

        // Create the company's first admin user
        $admin = User::create([
            'company_id' => $company->id,
            'name'       => $data['admin_name'],
            'email'      => $data['admin_email'],
            'password'   => Hash::make($data['admin_password']),
            'phone'      => $data['admin_phone'] ?? null,
            'is_active'  => true,
        ]);
        $admin->assignRole('admin');

        // Provision a WhatsApp session on the gateway for this company
        try {
            $this->gateway->createSession($company->session_id);
        } catch (\Throwable $e) {
            // Non-fatal — super-admin can retry from company detail page
            session()->flash('warning', 'Company created but gateway session failed: ' . $e->getMessage());
        }

        AuditService::log('superadmin.company_created', $company, [], [
            'name'        => $company->name,
            'admin_email' => $admin->email,
        ]);

        return redirect()->route('superadmin.companies.index')
                         ->with('success', "Company \"{$company->name}\" created with admin {$admin->email}.");
    }

    public function show(Company $company): Response
    {
        $this->authorizeSuperAdmin();

        $company->load(['users.roles', 'whatsappSessions' => fn($q) => $q->latest()->limit(1)]);

        $gatewayStatus = [];
        try {
            $gatewayStatus = $this->gateway->getStatus($company->session_id);
        } catch (\Throwable) {}

        return Inertia::render('SuperAdmin/Companies/Show', [
            'company'       => $company,
            'users'         => $company->users()->with('roles')->orderBy('name')->get(),
            'gatewayStatus' => $gatewayStatus,
            'stats' => [
                'customers' => $company->customers()->count(),
                'messages'  => $company->messages()->count(),
            ],
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'      => 'required|string|max:191',
            'is_active' => 'boolean',
        ]);

        $company->update($data);
        AuditService::log('superadmin.company_updated', $company);

        return back()->with('success', 'Company updated.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        // Delete gateway session first
        try {
            $this->gateway->deleteSession($company->session_id);
        } catch (\Throwable) {}

        AuditService::log('superadmin.company_deleted', $company);
        $company->delete();

        return redirect()->route('superadmin.companies.index')
                         ->with('success', 'Company deleted.');
    }

    // ── Re-provision gateway session ──────────────────────────────────────────

    public function provisionSession(Company $company): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        try {
            $this->gateway->createSession($company->session_id);
            return back()->with('success', 'Gateway session provisioned. A QR will appear on the gateway dashboard.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed: ' . $e->getMessage());
        }
    }
}