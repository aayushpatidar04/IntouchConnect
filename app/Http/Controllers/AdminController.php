<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    public function __construct()
    {
        // Must be company-admin or super-admin
        $this->middleware(function ($req, $next) {
            $user = $req->user();
            if ($user->hasRole('admin') || $user->hasRole('super_admin')) {
                return $next($req);
            }
            abort(403, 'Insufficient permissions.');
        });
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public function users(Request $request): Response
    {
        $authUser = auth()->user();

        // Super-admin sees all users; company-admin sees only their company
        $query = User::with('roles')
            ->when(! $authUser->isSuperAdmin(), fn($q) => $q->where('company_id', $authUser->company_id))
            ->orderBy('name');

        $users = $query->paginate(20);

        // Company-admin can only assign exec/auditor roles — not admin/super_admin
        $assignableRoles = $authUser->isSuperAdmin()
            ? Role::whereNotIn('name', ['super_admin'])->get()
            : Role::whereIn('name', ['executive', 'auditor'])->get();

        return Inertia::render('Admin/Users', [
            'users'           => $users,
            'assignableRoles' => $assignableRoles,
            'canManageAdmins' => $authUser->isSuperAdmin(),
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $authUser = auth()->user();

        $data = $request->validate([
            'name'     => 'required|string|max:191',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|exists:roles,name',
            'phone'    => 'nullable|string|max:20',
        ]);

        // Company-admins can't create other admins or super_admins
        if (! $authUser->isSuperAdmin() && in_array($data['role'], ['admin', 'super_admin'])) {
            abort(403, 'You cannot assign this role.');
        }

        $user = User::create([
            'company_id' => $authUser->isSuperAdmin()
                ? $request->input('company_id')   // super-admin can specify any company
                : $authUser->company_id,           // company-admin creates in their own company
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'phone'      => $data['phone'] ?? null,
            'is_active'  => true,
        ]);

        $user->assignRole($data['role']);

        AuditService::log('admin.user_created', $user, [], [
            'email' => $user->email,
            'role'  => $data['role'],
        ]);

        return back()->with('success', 'User created successfully.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $authUser = auth()->user();

        // Company-admin cannot modify users outside their company
        if (! $authUser->isSuperAdmin() && $user->company_id !== $authUser->company_id) {
            abort(403);
        }

        $data = $request->validate([
            'name'      => 'required|string|max:191',
            'email'     => "required|email|unique:users,email,{$user->id}",
            'role'      => 'required|exists:roles,name',
            'is_active' => 'boolean',
            'phone'     => 'nullable|string|max:20',
        ]);

        // Company-admin can't promote to admin/super_admin
        if (! $authUser->isSuperAdmin() && in_array($data['role'], ['admin', 'super_admin'])) {
            abort(403, 'You cannot assign this role.');
        }

        $user->update($data);
        $user->syncRoles([$data['role']]);
        AuditService::log('admin.user_updated', $user);

        return back()->with('success', 'User updated.');
    }

    public function destroyUser(User $user): RedirectResponse
    {
        $authUser = auth()->user();

        if ($user->id === $authUser->id) {
            return back()->with('error', 'Cannot delete yourself.');
        }

        // Company-admin cannot delete users from other companies
        if (! $authUser->isSuperAdmin() && $user->company_id !== $authUser->company_id) {
            abort(403);
        }

        // Prevent deleting super-admin
        if ($user->hasRole('super_admin')) {
            abort(403, 'Super-admin cannot be deleted.');
        }

        AuditService::log('admin.user_deleted', $user);
        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    // ── Audit logs ────────────────────────────────────────────────────────────

    public function auditLogs(Request $request): Response
    {
        $authUser = auth()->user();

        $logs = AuditLog::with('user')
            ->when(! $authUser->isSuperAdmin(), fn($q) => $q->where('company_id', $authUser->company_id))
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->action,  fn($q) => $q->where('action', 'like', "%{$request->action}%"))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $users = User::select('id', 'name')
            ->when(! $authUser->isSuperAdmin(), fn($q) => $q->where('company_id', $authUser->company_id))
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/AuditLogs', [
            'logs'    => $logs,
            'users'   => $users,
            'filters' => $request->only(['user_id', 'action']),
        ]);
    }
}