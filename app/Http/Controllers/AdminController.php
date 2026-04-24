<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(fn($req, $next) => $req->user()->hasRole('admin')
            ? $next($req)
            : abort(403));
    }

    public function users(): Response
    {
        $users = User::with('roles')->orderBy('name')->paginate(20);
        $roles = Role::all();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:191',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|exists:roles,name',
            'phone'    => 'nullable|string|max:20',
        ]);

        $user = User::create($data);
        $user->assignRole($data['role']);
        AuditService::log('admin.user_created', $user, [], ['email' => $user->email, 'role' => $data['role']]);

        return back()->with('success', 'User created.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:191',
            'email'     => "required|email|unique:users,email,{$user->id}",
            'role'      => 'required|exists:roles,name',
            'is_active' => 'boolean',
            'phone'     => 'nullable|string|max:20',
        ]);

        $user->update($data);
        $user->syncRoles([$data['role']]);
        AuditService::log('admin.user_updated', $user);

        return back()->with('success', 'User updated.');
    }

    public function destroyUser(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Cannot delete yourself.');
        }

        AuditService::log('admin.user_deleted', $user);
        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    public function auditLogs(Request $request): Response
    {
        $logs = AuditLog::with('user')
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->action,  fn($q) => $q->where('action', 'like', "%{$request->action}%"))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $users = User::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Admin/AuditLogs', [
            'logs'    => $logs,
            'users'   => $users,
            'filters' => $request->only(['user_id', 'action']),
        ]);
    }
}