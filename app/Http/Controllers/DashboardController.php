<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\GatewayService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private GatewayService $gateway) {}

    public function index(): Response
    {
        $user    = auth()->user();
        $isAdmin = $user->hasAnyRole(['admin', 'super_admin']);

        // Base query — CompanyScope automatically applies company_id filter.
        // For super-admin (no company), withoutGlobalScopes not needed here
        // because they have their own dashboard view.
        $baseCustomerQuery = $isAdmin
            ? Customer::query()
            : Customer::where('assigned_to', $user->id);

        $stats = [
            'total_customers'   => (clone $baseCustomerQuery)->count(),
            'active_customers'  => (clone $baseCustomerQuery)->where('status', 'active')->count(),
            'unread_messages'   => Message::where('direction', 'inbound')
                ->whereNull('read_at')
                ->when(! $isAdmin, fn($q) => $q->whereHas('customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
            'pending_documents' => Document::where('status', 'pending')
                ->when(! $isAdmin, fn($q) => $q->whereHas('customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
            'messages_today'    => Message::whereDate('created_at', today())
                ->when(! $isAdmin, fn($q) => $q->whereHas('customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
        ];

        $recentMessages = Message::with(['customer', 'sentBy'])
            ->when(! $isAdmin, fn($q) => $q->whereHas('customer',
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
            ->when(! $isAdmin, fn($q) => $q->whereHas('customer',
                fn($cq) => $cq->where('assigned_to', $user->id)
            ))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // WhatsApp status — scoped to this company's session
        $whatsappStatus = [];
        if ($user->company) {
            $whatsappStatus = $this->gateway
                ->setCompany($user->company)
                ->getCompanyStatus();
        } elseif ($user->isSuperAdmin()) {
            // Super-admin sees all sessions
            $whatsappStatus = $this->gateway->getStatus();
        }

        return Inertia::render('Dashboard/Index', [
            'stats'          => $stats,
            'recentMessages' => $recentMessages,
            'messageChart'   => $messageChart,
            'whatsappStatus' => $whatsappStatus,
            'isSuperAdmin'   => $user->isSuperAdmin(),
        ]);
    }
}