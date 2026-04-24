<?php

namespace App\Http\Controllers;

use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GatewayController extends Controller
{
    public function __construct(private GatewayService $gateway) {}

    public function webhook(Request $request): JsonResponse
    {
        $secret = $request->header('X-Gateway-Secret');

        if ($secret !== config('whatsapp.gateway_secret')) {
            Log::warning('Invalid gateway secret attempt from ' . $request->ip());
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        if (empty($payload['event'])) {
            return response()->json(['error' => 'Missing event'], 400);
        }

        try {
            $this->gateway->handleWebhook($payload);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Webhook handling error: ' . $e->getMessage(), $payload);
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    public function status(): JsonResponse
    {
        return response()->json($this->gateway->getStatus());
    }

    public function queueStats(): JsonResponse
    {
        return response()->json($this->gateway->getQueueStats());
    }

    public function logout(): JsonResponse
    {
        $success = $this->gateway->logout();
        return response()->json(['success' => $success]);
    }
}