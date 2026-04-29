<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\GatewayService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private GatewayService $gateway)
    {
    }

    public function index(): Response
    {
        $user = auth()->user();

        // Super-admin gets a completely separate platform-level dashboard.
        // They never see customers, messages, or documents — only company health.
        if ($user->isSuperAdmin()) {
            return $this->superAdminDashboard();
        }

        // Company-level dashboard (admin, executive, auditor)
        return $this->companyDashboard($user);
    }

    // ── Super Admin Dashboard ─────────────────────────────────────────────────
    // Tracks platform health: companies, sessions, user counts.
    // No customer/message/document data shown at all.

    private function superAdminDashboard(): Response
    {
        $stats = [
            'total_companies' => Company::count(),
            'active_companies' => Company::where('is_active', true)->count(),
            'total_users' => User::whereNotNull('company_id')->count(),
            'sessions_online' => WhatsappSession::where('status', 'connected')->count(),
            'sessions_offline' => WhatsappSession::whereIn('status', ['disconnected', 'failed'])->count(),
            'sessions_qr' => WhatsappSession::where('status', 'qr_ready')->count(),
        ];

        // All companies with session + admin info — no customer data
        $companies = Company::withCount('users')
            ->with([
                'whatsappSessions' => fn($q) => $q->latest()->limit(1),
                'admins' => fn($q) => $q->select('id', 'name', 'email', 'company_id'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn($company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'is_active' => $company->is_active,
                'users_count' => $company->users_count,
                'session_id' => $company->session_id,
                'session_status' => $company->whatsappSessions->first()?->status ?? 'disconnected',
                'session_phone' => $company->whatsappSessions->first()?->phone,
                'admin_name' => $company->admins->first()?->name,
                'admin_email' => $company->admins->first()?->email,
                'created_at' => $company->created_at,
            ]);

        // Company registrations per month — last 6 months
        $companyGrowth = Company::select(
            DB::raw("strftime('%Y-%m', created_at) as month"),
            DB::raw('COUNT(*) as total')
        )
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();


        // Live gateway session statuses from gateway API
        $gatewaySessions = [];
        try {
            $gatewaySessions = $this->gateway->getStatus();
        } catch (\Throwable) {
        }

        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => $stats,
            'companies' => $companies,
            'companyGrowth' => $companyGrowth,
            'gatewaySessions' => $gatewaySessions,
        ]);
    }

    // ── Company Dashboard (admin, executive, auditor) ─────────────────────────

    private function companyDashboard(User $user): Response
    {
        $isAdmin = $user->hasRole('admin');

        $baseCustomerQuery = $isAdmin
            ? Customer::query()
            : Customer::where('assigned_to', $user->id);

        $stats = [
            'total_customers' => (clone $baseCustomerQuery)->count(),
            'active_customers' => (clone $baseCustomerQuery)->where('status', 'active')->count(),
            'unread_messages' => Message::where('direction', 'inbound')
                ->whereNull('read_at')
                ->when(!$isAdmin, fn($q) => $q->whereHas(
                    'customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
            'pending_documents' => Document::where('status', 'pending')
                ->when(!$isAdmin, fn($q) => $q->whereHas(
                    'customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
            'messages_today' => Message::whereDate('created_at', today())
                ->when(!$isAdmin, fn($q) => $q->whereHas(
                    'customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
        ];

        $recentMessages = Message::with(['customer', 'sentBy'])
            ->when(!$isAdmin, fn($q) => $q->whereHas(
                'customer',
                fn($cq) => $cq->where('assigned_to', $user->id)
            ))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $messageChart = Message::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) as inbound"),
            DB::raw("SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound")
        )
            ->whereBetween('created_at', [now()->subDays(6)->startOfDay(), now()->endOfDay()])
            ->when(!$isAdmin, fn($q) => $q->whereHas(
                'customer',
                fn($cq) => $cq->where('assigned_to', $user->id)
            ))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $whatsappStatus = [];
        if ($user->company) {
            $whatsappStatus = $this->gateway
                ->setCompany($user->company)
                ->getCompanyStatus();
        }

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'recentMessages' => $recentMessages,
            'messageChart' => $messageChart,
            'whatsappStatus' => $whatsappStatus,
        ]);
    }
}