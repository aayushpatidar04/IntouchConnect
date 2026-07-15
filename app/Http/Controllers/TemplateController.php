<?php

namespace App\Http\Controllers;

use App\Jobs\SendTemplateBroadcastJob;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Template;
use App\Models\TemplateBroadcast;
use App\Models\TemplateBroadcastRecipient;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    //  SUPERADMIN: Global Template CRUD
    // ═══════════════════════════════════════════════════════════════════════

    public function adminIndex(Request $request): Response
    {
        $this->requireSuperAdmin();

        $templates = Template::with(['createdBy:id,name'])
            ->withCount('broadcasts')
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn($t) => $this->formatTemplateForAdmin($t));

        return Inertia::render('Templates/AdminIndex', [
            'templates' => $templates,
            'categories' => Template::categoryMeta(),
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function adminStore(Request $request): RedirectResponse
    {
        $this->requireSuperAdmin();

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'body' => 'required|string|max:4096',
            'category' => 'required|in:general,followup,promo,reminder',
            'is_active' => 'boolean',
            'media' => 'nullable|file|max:5120',
        ]);

        if ($request->hasFile('media')) {
            $data['media'] = $request->file('media')->store('templates', 'public');
        }

        $template = Template::create([
            'name' => $data['name'],
            'body' => $data['body'],
            'category' => $data['category'],
            'is_active' => $data['is_active'] ?? true,
            'media' => $data['media'] ?? null,
            'created_by' => auth()->id(),
        ]);

        AuditService::log('template.created', $template, [], ['name' => $template->name]);

        return back()->with('success', "Template \"{$template->name}\" created.");
    }

    public function adminUpdate(Request $request, Template $template): RedirectResponse
    {
        $this->requireSuperAdmin();

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'body' => 'required|string|max:4096',
            'category' => 'required|in:general,followup,promo,reminder',
            'is_active' => 'boolean',
            'media' => 'nullable|file|max:10240',
        ]);

        if ($request->hasFile('media')) {
            $data['media'] = $request->file('media')->store('templates', 'public');
        }

        $old = $template->only(['name', 'body', 'category', 'is_active']);
        $template->update($data);

        AuditService::log('template.updated', $template, $old, $data);

        return back()->with('success', 'Template updated.');
    }

    public function adminDestroy(Template $template): RedirectResponse
    {
        $this->requireSuperAdmin();

        AuditService::log('template.deleted', $template);
        $template->delete();

        return back()->with('success', 'Template deleted.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  COMPANY ADMIN: Assign executives to global templates
    // ═══════════════════════════════════════════════════════════════════════

    public function companyAssignments(Request $request): Response
    {
        $user = auth()->user();
        $this->requireAdmin();

        $templates = Template::with(['assignedUsers:id,name'])
            ->where('is_active', true)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn($t) => $this->formatTemplateForCompany($t, $user->company_id));

        $executives = User::where('company_id', $user->company_id)
            ->role('executive')
            ->select('id', 'name')
            ->get();

        return Inertia::render('Templates/CompanyAssignments', [
            'templates' => $templates,
            'executives' => $executives,
            'categories' => Template::categoryMeta(),
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function updateAssignments(Request $request, Template $template): RedirectResponse
    {
        $this->requireAdmin();

        $data = $request->validate([
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'integer|exists:users,id',
        ]);

        $companyId = auth()->user()->company_id;

        // Validate users belong to this company and are executives
        $validIds = User::where('company_id', $companyId)
            ->role('executive')
            ->whereIn('id', $data['assigned_users'] ?? [])
            ->pluck('id');

        // Preserve assignments from other companies
        $otherCompanyIds = $template->assignedUsers()
            ->where('company_id', '!=', $companyId)
            ->pluck('users.id');

        $allAssignedIds = $otherCompanyIds->merge($validIds)->unique()->values();

        $template->assignedUsers()->sync($allAssignedIds);

        AuditService::log('template.assignments_updated', $template, [], [
            'company_id' => $companyId,
            'assigned_count' => $validIds->count(),
        ]);

        return back()->with('success', 'Template assignments updated.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  EXECUTIVE: List & Send Templates (existing, updated)
    // ═══════════════════════════════════════════════════════════════════════

    public function index(Request $request): Response
    {
        $user = auth()->user();

        $templates = Template::with(['createdBy:id,name', 'assignedUsers:id,name'])
            ->withCount('broadcasts')
            ->where('is_active', true)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when(!$user->hasAnyRole(['admin', 'super_admin']), function ($q) use ($user) {
                $q->where(function ($inner) use ($user) {
                    $inner->whereHas('assignedUsers', fn($u) => $u->where('user_id', $user->id))
                        ->orWhereDoesntHave('assignedUsers');
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn($t) => $this->formatTemplate($t));

        $executives = User::where('company_id', $user->company_id)
            ->role('executive')
            ->select('id', 'name')
            ->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'executives' => $executives,
            'categories' => Template::categoryMeta(),
            'isAdmin' => $user->hasRole('admin'),
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function show(Template $template): JsonResponse
    {
        if (!$template->is_active) {
            return response()->json(['error' => 'Template is inactive.'], 403);
        }
        if (!$template->isAccessibleBy(auth()->user())) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        return response()->json($this->formatTemplate($template));
    }

    public function preview(Request $request, Template $template): JsonResponse
    {
        if (!$template->isAccessibleBy(auth()->user())) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        $values = $request->validate(['values' => 'array']);
        return response()->json(['preview' => $template->resolve($values['values'] ?? [])]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  BROADCAST (unchanged — broadcasts remain company-scoped)
    // ═══════════════════════════════════════════════════════════════════════

    public function broadcast(Request $request, Template $template): JsonResponse
    {
        if (!$template->isAccessibleBy(auth()->user())) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        if (!$template->is_active) {
            return response()->json(['error' => 'Template is inactive.'], 422);
        }

        $data = $request->validate([
            'customer_ids' => 'array|max:500',
            'customer_ids.*' => 'integer|exists:customers,id',
            'group_ids' => 'array',
            'group_ids.*' => 'integer|exists:groups,id',
            'variable_values' => 'nullable|array',
        ]);

        $user = auth()->user();
        $customerIds = $data['customer_ids'] ?? [];

        if (!empty($data['group_ids'])) {
            $groupCustomerIds = CustomerGroup::whereIn('group_id', $data['group_ids'])
                ->pluck('customer_id')->toArray();
            $customerIds = array_merge($customerIds, $groupCustomerIds);
        }

        $customerIds = array_unique($customerIds);
        if (empty($customerIds)) {
            return response()->json(['error' => 'No customers selected.'], 422);
        }

        $customers = Customer::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->whereIn('id', $customerIds)
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['error' => 'No valid customers selected.'], 422);
        }

        $variableValues = $data['variable_values'] ?? [];

        $broadcast = TemplateBroadcast::create([
            'company_id' => $user->company_id,
            'template_id' => $template->id,
            'sent_by' => $user->id,
            'variable_values' => $variableValues,
            'total_recipients' => $customers->count(),
            'pending_count' => $customers->count(),
            'status' => 'running',
            'started_at' => now(),
        ]);

        $cumulativeDelay = now();
        foreach ($customers as $customer) {
            $perCustomerValues = array_merge($variableValues, [
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_email' => $customer->email ?? '',
            ]);
            $resolvedBody = $template->resolve($perCustomerValues);

            $recipient = TemplateBroadcastRecipient::create([
                'broadcast_id' => $broadcast->id,
                'customer_id' => $customer->id,
                'status' => 'pending',
                'resolved_body' => $resolvedBody,
            ]);

            SendTemplateBroadcastJob::dispatch($broadcast->id, $recipient->id)
                ->delay($cumulativeDelay)
                ->onQueue('broadcasts');

            $cumulativeDelay = $cumulativeDelay->copy()->addSeconds(rand(4, 6));
        }

        AuditService::log('template.broadcast', $template, [], [
            'broadcast_id' => $broadcast->id,
            'recipients' => $customers->count(),
        ]);

        return response()->json([
            'success' => true,
            'broadcast_id' => $broadcast->id,
            'total' => $customers->count(),
            'eta_minutes' => round($cumulativeDelay->diffInMinutes(now())),
            'message' => "Broadcast queued for {$customers->count()} recipients.",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  BROADCAST HISTORY (company-scoped)
    // ═══════════════════════════════════════════════════════════════════════

    public function broadcastHistory(Request $request): Response
    {
        $user = auth()->user();

        $broadcasts = TemplateBroadcast::with(['template:id,name,category', 'sentBy:id,name'])
            ->where('company_id', $user->company_id)
            ->when(!$user->hasRole('admin'), fn($q) => $q->where('sent_by', $user->id))
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('Templates/BroadcastHistory', [
            'broadcasts' => $broadcasts,
            'isAdmin' => $user->hasRole('admin'),
        ]);
    }

    public function broadcastShow(TemplateBroadcast $broadcast): JsonResponse
    {
        $user = auth()->user();

        if ($broadcast->company_id !== $user->company_id) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (!$user->hasRole('admin') && $broadcast->sent_by !== $user->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $recipients = $broadcast->recipients()
            ->with('customer:id,name,phone')
            ->orderBy('status')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'customer_name' => $r->customer->name,
                'customer_phone' => $r->customer->phone,
                'status' => $r->status,
                'failure_reason' => $r->failure_reason,
                'sent_at' => $r->sent_at?->toISOString(),
            ]);

        return response()->json([
            'broadcast' => [
                'id' => $broadcast->id,
                'status' => $broadcast->status,
                'total_recipients' => $broadcast->total_recipients,
                'sent_count' => $broadcast->sent_count,
                'failed_count' => $broadcast->failed_count,
                'pending_count' => $broadcast->pending_count,
                'progress_percent' => $broadcast->progress_percent,
                'started_at' => $broadcast->started_at?->toISOString(),
                'completed_at' => $broadcast->completed_at?->toISOString(),
            ],
            'recipients' => $recipients,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function requireSuperAdmin(): void
    {
        if (!auth()->user()->hasRole('super_admin')) {
            abort(403, 'Only superadmins can manage global templates.');
        }
    }

    private function requireAdmin(): void
    {
        if (!auth()->user()->hasAnyRole(['admin', 'super_admin'])) {
            abort(403, 'Only admins can manage template assignments.');
        }
    }

    private function formatTemplate(Template $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'body' => $t->body,
            'media' => $t->media ? '/storage/' . $t->media : null,
            'variables' => $t->variables ?? [],
            'category' => $t->category,
            'is_active' => $t->is_active,
            'broadcasts_count' => $t->broadcasts_count ?? 0,
            'created_by' => $t->createdBy?->name,
            'assigned_users' => $t->assignedUsers?->map(fn($u) => ['id' => $u->id, 'name' => $u->name])->toArray() ?? [],
            'assigned_all' => ($t->assignedUsers?->count() ?? 0) === 0,
            'updated_at' => $t->updated_at?->toISOString(),
        ];
    }

    private function formatTemplateForAdmin(Template $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'body' => $t->body,
            'media' => $t->media ? '/storage/' . $t->media : null,
            'variables' => $t->variables ?? [],
            'category' => $t->category,
            'is_active' => $t->is_active,
            'broadcasts_count' => $t->broadcasts_count ?? 0,
            'created_by' => $t->createdBy?->name,
            'updated_at' => $t->updated_at?->toISOString(),
        ];
    }

    private function formatTemplateForCompany(Template $t, int $companyId): array
    {
        $companyAssigned = $t->assignedUsers
            ?->where('company_id', $companyId)
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->toArray() ?? [];

        return [
            'id' => $t->id,
            'name' => $t->name,
            'body' => $t->body,
            'media' => $t->media ? '/storage/' . $t->media : null,
            'variables' => $t->variables ?? [],
            'category' => $t->category,
            'is_active' => $t->is_active,
            'assigned_users' => $companyAssigned,
            'assigned_all' => ($t->assignedUsers?->count() ?? 0) === 0,
            'updated_at' => $t->updated_at?->toISOString(),
        ];
    }
}
