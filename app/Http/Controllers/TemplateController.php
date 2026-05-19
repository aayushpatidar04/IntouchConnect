<?php

namespace App\Http\Controllers;

use App\Jobs\SendTemplateBroadcastJob;
use App\Models\Customer;
use App\Models\Template;
use App\Models\TemplateBroadcast;
use App\Models\TemplateBroadcastRecipient;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    // ── Admin: Template list ──────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $user = auth()->user();

        $templates = Template::with(['createdBy:id,name', 'assignedUsers:id,name'])
            ->withCount('broadcasts')
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when(!$user->hasRole('admin'), function ($q) use ($user) {
                // Executives see only templates assigned to them OR templates with no assignments
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

    // ── Admin: Create template ────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->requireAdmin();

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'body' => 'required|string|max:4096',
            'category' => 'required|in:general,followup,promo,reminder',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'integer|exists:users,id',
            'media' => 'nullable|file|max:5120',
        ]);

        if ($request->hasFile('media')) {
            $data['media'] = $request->file('media')->store('templates', 'public');
        }

        $template = Template::create([
            'name' => $data['name'],
            'body' => $data['body'],
            'category' => $data['category'],
            'media' => $data['media'] ?? null,
        ]);

        // Sync assigned users
        if (isset($data['assigned_users'])) {
            // Validate that all assigned users belong to this company
            $validIds = User::where('company_id', auth()->user()->company_id)
                ->whereIn('id', $data['assigned_users'])
                ->pluck('id');
            $template->assignedUsers()->sync($validIds);
        }

        AuditService::log('template.created', $template, [], ['name' => $template->name]);

        return back()->with('success', "Template \"{$template->name}\" created.");
    }

    // ── Admin: Update template ────────────────────────────────────────────────

    public function update(Request $request, Template $template): RedirectResponse
    {
        $this->requireAdmin();

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'body' => 'required|string|max:4096',
            'category' => 'required|in:general,followup,promo,reminder',
            'is_active' => 'boolean',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'integer|exists:users,id',
            'media' => 'nullable|file|max:10240',
        ]);

        if ($request->hasFile('media')) {
            $data['media'] = $request->file('media')->store('templates', 'public');
        }

        $old = $template->only(['name', 'body', 'category', 'is_active']);
        $template->update($data);

        // Sync assigned users
        $assignedUsers = $data['assigned_users'] ?? [];
        $validIds = User::where('company_id', auth()->user()->company_id)
            ->whereIn('id', $assignedUsers)
            ->pluck('id');
        $template->assignedUsers()->sync($validIds);

        AuditService::log('template.updated', $template, $old, $data);

        return back()->with('success', 'Template updated.');
    }

    // ── Admin: Delete template ────────────────────────────────────────────────

    public function destroy(Template $template): RedirectResponse
    {
        $this->requireAdmin();
        AuditService::log('template.deleted', $template);
        $template->delete();
        return back()->with('success', 'Template deleted.');
    }

    // ── Executive: Get a single template (for send modal) ────────────────────

    public function show(Template $template): JsonResponse
    {
        if (!$template->isAccessibleBy(auth()->user())) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        return response()->json($this->formatTemplate($template));
    }

    // ── Executive: Preview resolved body ─────────────────────────────────────

    public function preview(Request $request, Template $template): JsonResponse
    {
        if (!$template->isAccessibleBy(auth()->user())) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $values = $request->validate(['values' => 'array']);
        $preview = $template->resolve($values['values'] ?? []);

        return response()->json(['preview' => $preview]);
    }

    // ── Executive: Dispatch broadcast ─────────────────────────────────────────

    public function broadcast(Request $request, Template $template): JsonResponse
    {
        if (!$template->isAccessibleBy(auth()->user())) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        if (!$template->is_active) {
            return response()->json(['error' => 'Template is inactive.'], 422);
        }

        $data = $request->validate([
            'customer_ids' => 'required|array|min:1|max:500',
            'customer_ids.*' => 'integer|exists:customers,id',
            'variable_values' => 'nullable|array',
        ]);

        $user = auth()->user();

        // Validate customers belong to this company
        $customers = Customer::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->whereIn('id', $data['customer_ids'])
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['error' => 'No valid customers selected.'], 422);
        }

        $variableValues = $data['variable_values'] ?? [];

        // Create broadcast record
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

        // Dispatch one job per recipient with human-like staggered delays.
        // Strategy:
        //   - First message: 5–15s (give gateway time to wake up)
        //   - Subsequent: 30–90s each (realistic human pace ~1 msg/min)
        //   - After every 20 messages: 3–5 minute pause
        $limitPerMinute = 15;
        $interval = 60 / $limitPerMinute; // 4 seconds

        $cumulativeDelay = now();

        foreach ($customers as $index => $customer) {
            // Resolve body per customer — replace {{customer_name}} with actual name etc.
            $perCustomerValues = array_merge($variableValues, [
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_email' => $customer->email ?? '',
            ]);
            $resolvedBody = $template->resolve($perCustomerValues);

            // Create recipient record
            $recipient = TemplateBroadcastRecipient::create([
                'broadcast_id' => $broadcast->id,
                'customer_id' => $customer->id,
                'status' => 'pending',
                'resolved_body' => $resolvedBody,
            ]);

            // Dispatch with delay
            SendTemplateBroadcastJob::dispatch($broadcast->id, $recipient->id)
                ->delay($cumulativeDelay)
                ->onQueue('broadcasts');

            // Advance delay for next message
            $cumulativeDelay = $cumulativeDelay->copy()->addSeconds($interval);
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
            'message' => "Broadcast queued for {$customers->count()} recipients. Messages will be sent with natural delays to avoid blocks.",
        ]);
    }

    // ── Broadcast history (for admin and exec) ────────────────────────────────

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

    // ── Broadcast detail (progress per recipient) ─────────────────────────────

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireAdmin(): void
    {
        if (!auth()->user()->hasAnyRole(['admin', 'super_admin'])) {
            abort(403, 'Only admins can manage templates.');
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
}