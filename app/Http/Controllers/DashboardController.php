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
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private GatewayService $gateway)
    {
    }

    public function index(Request $request): Response
    {
        $user = auth()->user();

        // Super-admin gets a completely separate platform-level dashboard.
        // They never see customers, messages, or documents — only company health.
        if ($user->isSuperAdmin()) {
            return $this->superAdminDashboard();
        }

        // Company-level dashboard (admin, executive, auditor)
        return $this->companyDashboard($request, $user);
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
            ->latest()
	    ->limit(5)
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
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
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

    private function companyDashboard($request, User $user): Response
    {
        $isAdmin = $user->hasRole('admin');
	$search = $request->input('search');

	$baseCustomerQuery = Customer::withoutGlobalScope(
	    \App\Scopes\CompanyScope::class
	)->visibleTo($user);

	$visibleMessages = Message::withoutGlobalScope(
	    \App\Scopes\CompanyScope::class
	)->visibleTo($user);

        $stats = [
            'total_customers' => (clone $baseCustomerQuery)->count(),
            'active_customers' => (clone $baseCustomerQuery)->where('status', 'active')->count(),
	    'unread_messages' => (clone $visibleMessages)
	        ->where('direction', 'inbound')
	        ->whereNull('read_at')
	        ->count(),
            'pending_documents' => Document::where('status', 'pending')
                ->when(!$isAdmin, fn($q) => $q->whereHas(
                    'customer',
                    fn($cq) => $cq->where('assigned_to', $user->id)
                ))
                ->count(),
	    'messages_today' => (clone $visibleMessages)
	        ->whereDate('created_at', today())
	        ->count(),
        ];

	$visibleMessageQuery = Message::withoutGlobalScope(
            \App\Scopes\CompanyScope::class
        )->visibleTo($user);

        $latestVisibleMessageIds = (clone $visibleMessageQuery)
            ->selectRaw('MAX(messages.id)')
            ->groupBy('messages.customer_id');

        $recentMessages = (clone $visibleMessageQuery)
            ->with([
                'customer' => function ($query) {
                    $query
                        ->withoutGlobalScope(
                            \App\Scopes\CompanyScope::class
                        )
                        ->with([
                            'assignedTo:id,name,company_id',
                            'oldOwner:id,name,company_id',
                        ]);
                },
                'sentBy:id,name',
                'document',
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->whereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery
                                ->withoutGlobalScope(
                                    \App\Scopes\CompanyScope::class
                                )
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                );
                        })
                        ->orWhere(
                            'messages.body',
                            'like',
                            "%{$search}%"
                        );
                });
            })
            ->whereIn(
                'messages.id',
                $latestVisibleMessageIds
            )
            ->orderByDesc('messages.created_at')
            ->paginate(20)
            ->withQueryString();

	$messageChart = (clone $visibleMessages)
	    ->select(
	        DB::raw('DATE(created_at) as date'),
	        DB::raw('COUNT(*) as total'),
	        DB::raw(
	            "SUM(
	                CASE
	                    WHEN direction = 'inbound'
	                    THEN 1
	                    ELSE 0
	                END
	            ) as inbound"
	        ),
	        DB::raw(
	            "SUM(
	                CASE
	                    WHEN direction = 'outbound'
	                    THEN 1
	                    ELSE 0
	                END
	            ) as outbound"
	        )
	    )
	    ->whereBetween('created_at', [
	        now()->subDays(6)->startOfDay(),
	        now()->endOfDay(),
	    ])
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
	    'filters' => ['search' => $search],
        ]);
    }
    
    public function unreadMessages(Request $request)
{
    $user = $request->user();

    /*
     * All unread inbound messages visible to the current user.
     *
     * Message::visibleTo() must already restrict messages by
     * receiving company/session.
     */
    $visibleUnreadMessages = Message::withoutGlobalScope(
        \App\Scopes\CompanyScope::class
    )
        ->visibleTo($user)
        ->where('messages.direction', 'inbound')
        ->whereNull('messages.read_at');

    /*
     * Total unread MESSAGE count.
     *
     * This remains the number displayed in the red badge/stat card.
     * For example, one customer with five unread messages contributes 5.
     */
    $unreadCount = (clone $visibleUnreadMessages)->count();

    /*
     * Group unread messages by conversation.
     *
     * customer_id + session_id is required because a transferred
     * customer can have separate conversations through:
     *
     * - assigned owner's WhatsApp number
     * - old owner's WhatsApp number
     */
    $unreadGroups = (clone $visibleUnreadMessages)
        ->selectRaw('
            messages.customer_id,
            messages.session_id,
            messages.company_id,
            MAX(messages.id) as latest_message_id,
            COUNT(messages.id) as unread_count
        ')
        ->groupBy(
            'messages.customer_id',
            'messages.session_id',
            'messages.company_id'
        );

    /*
     * Fetch the latest unread message from every grouped conversation.
     */
    $unreadChats = Message::withoutGlobalScope(
        \App\Scopes\CompanyScope::class
    )
        ->joinSub(
            $unreadGroups,
            'unread_groups',
            function ($join) {
                $join->on(
                    'messages.id',
                    '=',
                    'unread_groups.latest_message_id'
                );
            }
        )
        ->with([
            'customer' => function ($query) {
                $query
                    ->withoutGlobalScope(
                        \App\Scopes\CompanyScope::class
                    )
                    ->select([
                        'id',
                        'name',
                        'phone',
                        'assigned_to',
                        'old_owner_id',
                    ]);
            },
            'sentBy:id,name',
            'document',
        ])
        ->select([
            'messages.*',
            'unread_groups.unread_count',
        ])
        ->orderByDesc('messages.created_at')
        ->limit(20)
        ->get()
        ->map(function (Message $message) {
            return [
                /*
                 * Use a conversation key because the same customer
                 * can have different WhatsApp sessions.
                 */
                'conversation_key' =>
                    "{$message->customer_id}:{$message->session_id}",

                'latest_message_id' =>
                    $message->id,

                'customer_id' =>
                    $message->customer_id,

                'customer_name' =>
                    $message->customer?->name
                    ?? 'Unknown',

                'customer_phone' =>
                    $message->customer?->phone,

                'session_id' =>
                    $message->session_id,

                'company_id' =>
                    $message->company_id,

                'body' =>
                    $message->body,

                'type' =>
                    $message->type,

                'has_document' =>
                    $message->document !== null,

                /*
                 * Number of unread messages in this conversation.
                 */
                'unread_count' =>
                    (int) $message->unread_count,

                'created_at' =>
                    $message->created_at?->toISOString(),

                'time_ago' =>
                    $message->created_at
                        ? $message->created_at->diffForHumans()
                        : '',
            ];
        });

    return response()->json([
        /*
         * Total number of individual unread messages.
         */
        'unread_count' => $unreadCount,

        /*
         * Number of conversations containing unread messages.
         */
        'unread_chat_count' => $unreadChats->count(),

        /*
         * One item per customer/session conversation.
         */
        'unread_chats' => $unreadChats,
    ]);
}

}
