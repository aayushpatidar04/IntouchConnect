<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class); // admin or auditor only

        $days  = (int) $request->get('days', 30);
        $start = now()->subDays($days - 1)->startOfDay();
        $end   = now()->endOfDay();

        // Message volume by day
        $volumeByDay = Message::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN direction='inbound'  THEN 1 ELSE 0 END) as inbound"),
                DB::raw("SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound")
            )
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top executives by response count
        $topExecutives = User::withCount(['sentMessages as messages_sent' => function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end]);
            }])
            ->role('executive')
            ->orderByDesc('messages_sent')
            ->limit(10)
            ->get(['id', 'name']);

        // Average response time (minutes) per executive
        // Approximation: time from last inbound to next outbound per customer
        $responseTime = DB::select("
            SELECT u.name, ROUND(AVG(TIMESTAMPDIFF(MINUTE, inbound_msg.created_at, outbound_msg.created_at)), 1) as avg_minutes
            FROM messages inbound_msg
            JOIN customers c ON inbound_msg.customer_id = c.id
            JOIN users u ON c.assigned_to = u.id
            JOIN messages outbound_msg ON outbound_msg.customer_id = inbound_msg.customer_id
                AND outbound_msg.direction = 'outbound'
                AND outbound_msg.created_at > inbound_msg.created_at
                AND outbound_msg.created_at = (
                    SELECT MIN(created_at) FROM messages
                    WHERE customer_id = inbound_msg.customer_id
                      AND direction = 'outbound'
                      AND created_at > inbound_msg.created_at
                )
            WHERE inbound_msg.direction = 'inbound'
              AND inbound_msg.created_at BETWEEN ? AND ?
            GROUP BY u.id, u.name
            ORDER BY avg_minutes ASC
            LIMIT 10
        ", [$start, $end]);

        // Document stats
        $documentStats = Document::select('status', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->pluck('count', 'status');

        // Customer engagement
        $newCustomers = Customer::whereBetween('created_at', [$start, $end])->count();
        $activeCustomers = Customer::whereHas('messages', fn($q) => $q->whereBetween('created_at', [$start, $end]))->count();

        return Inertia::render('Analytics/Index', [
            'volumeByDay'    => $volumeByDay,
            'topExecutives'  => $topExecutives,
            'responseTime'   => $responseTime,
            'documentStats'  => $documentStats,
            'newCustomers'   => $newCustomers,
            'activeCustomers'=> $activeCustomers,
            'days'           => $days,
        ]);
    }
}