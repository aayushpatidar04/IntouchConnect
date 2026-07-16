<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\User;

class GatewayController extends Controller
{
    private const LEAD_API_URL = 'https://arihantapicore.arihantcapital.com/V1/bitrix24/lead/';
    private const API_USERNAME = 'Arihant';
    private const API_PASSWORD = 'Arihant@2021';

    public function __construct(private GatewayService $gateway) {}

    /**
     * Single webhook endpoint for ALL companies.
     * Company is identified purely by X-Gateway-Secret matching a company slug.
     *
     * Wait — the gateway sends ONE shared GATEWAY_SECRET from .env.
     * Company is identified by session_id in the payload body.
     * The secret validates that the request actually came from OUR gateway.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = $request->header('X-Gateway-Secret');

        if ($secret !== config('whatsapp.gateway_secret')) {
            Log::warning('Invalid gateway secret from ' . $request->ip());
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

    public function uploadMedia(Request $request)
    {
        if ($request->header('X-Gateway-Secret') !== config('whatsapp.gateway_secret')) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $fileName   = $request->header('X-File-Name');
        $mimeType   = $request->header('Content-Type') ?: 'application/octet-stream';
        $sessionId  = $request->header('X-Session-Id');
        $fromPhone  = $request->header('X-From-Phone');
        $messageId  = $request->header('X-Message-Id');
        $mediaType  = $request->header('X-Media-Type');

        if (!$fileName) {
            return response()->json(['success' => false, 'error' => 'Missing file name'], 400);
        }

        if (!$fromPhone) {
            return response()->json(['success' => false, 'error' => 'Missing from phone'], 400);
        }

        $content = file_get_contents('php://input');
        if ($content === false || strlen($content) === 0) {
            return response()->json(['success' => false, 'error' => 'Empty upload body'], 400);
        }

        $normalizedPhone = preg_replace('/\D/', '', $fromPhone);
        if (empty($normalizedPhone)) {
            return response()->json(['success' => false, 'error' => 'Invalid from phone'], 400);
        }

        $company = null;
        if ($sessionId) {
            $company = Company::where('slug', $sessionId)->first();
        }

        $customerQuery = Customer::withoutGlobalScopes()
            ->where('phone', $normalizedPhone);

        if ($company) {
            $customerQuery->where('company_id', $company->id);
        }

        $customer = $customerQuery->first();
        if (!$customer) {
            return response()->json(['success' => false, 'error' => 'Customer not found'], 404);
        }

        $safeFileName  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        $directory     = 'documents/' . $customer->id . '/' . now()->format('Y-m');
        $storagePath   = $directory . '/' . $safeFileName;

        Storage::disk('public')->put($storagePath, $content);

        return response()->json([
            'success' => true,
            'media_url' => Storage::disk('public')->url($storagePath),
            'media_id' => (string) Str::uuid(),
            'filename' => $fileName,
            'mimetype' => $mimeType,
            'size_bytes' => strlen($content),
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'from_phone' => $fromPhone,
            'customer_id' => $customer->id,
            'media_type' => $mediaType,
        ]);
    }

    /**
     * Get status of all gateway sessions (super-admin) or just
     * the authenticated company's session (company admin/exec).
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return response()->json($this->gateway->getStatus());
        }

        // Company-scoped user — only their session
        $company = $user->company;
        if (! $company) {
            return response()->json(['status' => 'disconnected', 'is_ready' => false]);
        }

        return response()->json(
            $this->gateway->setCompany($company)->getCompanyStatus()
        );
    }

    public function queueStats(): JsonResponse
    {
        return response()->json($this->gateway->getQueueStats());
    }

    /**
     * Logout a company's WhatsApp session.
     * Super-admin can logout any session, company admin only their own.
     */
    public function logout(Request $request): JsonResponse
    {
        $user      = $request->user();
        $sessionId = $request->input('session_id');

        if (! $user->isSuperAdmin()) {
            // Force to their own company's session
            $sessionId = $user->company?->session_id;
        }

        if (! $sessionId) {
            return response()->json(['error' => 'session_id required'], 400);
        }

        $success = $this->gateway->logout($sessionId);
        return response()->json(['success' => $success]);
    }

    /**
     * Create a gateway session for a company (called from CompanyController).
     */
    public function createSession(Request $request): JsonResponse
    {
        if (!auth()->user()->hasRole('admin')) {
            return response()->json(['error' => 'Only admins can connect WhatsApp'], 403);
        }

        $companyId = $request->input('company_id', auth()->user()->company_id);
    
        try {
            $company = Company::findOrFail($companyId);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Company not found'], 404);
        }

        try {
            Log::info('Calling gateway createSession', [
                'company' => $company->slug,
                'session_id' => $company->slug,
                'gateway_url' => config('whatsapp.gateway_url'),
            ]);

            $result = $this->gateway->createSession($company->slug);
        
            Log::info("Gateway session created for company {$company->slug}", ['result' => $result]);
        
            return response()->json([
                'success' => true, 
                'result' => $result,
                'message' => 'WhatsApp session created successfully'
            ]);
        
        } catch (\Throwable $e) {
            Log::error('Gateway session create failed', [
                'company' => $company->slug,
                'session_id' => $company->slug,
                'gateway_url' => config('whatsapp.gateway_url'),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        
            return response()->json([
                'error' => 'WhatsApp connection failed: ' . $e->getMessage()
            ], 500);
        }
    }
	
   public function leads(Request $request)
    {
        $event = $request->input('event');
        $leadId = $request->input('data.FIELDS.ID');

        Log::info('Bitrix24 Webhook received', [
            'event' => $event,
            'lead_id' => $leadId,
        ]);

        if (!$leadId) {
            Log::warning('Bitrix24 Webhook: No Lead ID found.');
            return response()->json(['error' => 'Missing Lead ID'], 400);
        }

        // Handle DELETE event
        if ($event === 'ONCRMLEADDELETE') {
            return $this->handleLeadDelete($leadId);
        }

        // Handle ADD and UPDATE events
        if (in_array($event, ['ONCRMLEADADD', 'ONCRMLEADUPDATE'])) {
            return $this->handleLeadUpsert($leadId);
        }

        Log::warning('Bitrix24 Webhook: Unknown event type', ['event' => $event]);
        return response()->json(['error' => 'Unknown event type'], 400);
    }

    private function handleLeadUpsert(int $leadId): \Illuminate\Http\JsonResponse
    {
        $url = self::LEAD_API_URL . $leadId;
        
        $response = Http::withBasicAuth(self::API_USERNAME, self::API_PASSWORD)
            ->withHeaders(['Accept' => '*/*'])
            ->get($url);

        if (!$response->successful()) {
            Log::error("Failed to fetch lead {$leadId} from API", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return response()->json(['error' => 'Failed to fetch lead data'], 500);
        }

        $leadData = $response->json();

        Log::info("Lead {$leadId} fetched from API", $leadData);

        // Validate required fields
        if (empty($leadData['LeadId']) || empty($leadData['AssignedById'])) {
            Log::warning("Lead {$leadId} missing required fields", $leadData);
            return response()->json(['error' => 'Invalid lead data'], 400);
        }

        // Find the assigned user in our database by Bitrix user ID
        $assignedUser = User::where('bitrix_user_id', $leadData['AssignedById'])->first();

        if (!$assignedUser) {
            Log::warning("No user found with bitrix_user_id: {$leadData['AssignedById']} for lead {$leadId}");
            return response()->json([
                'status' => 'skipped',
                'message' => "No matching user found for AssignedById {$leadData['AssignedById']}",
            ], 200);
        }
	
	$oldOwnerId = null;
	if (!empty($leadData['Observers'])) {
	    $oldOwner = User::where('bitrix_user_id', $leadData['Observers'])->first();
	    $oldOwnerId = $oldOwner ? $oldOwner->id : null;
	}
		
        // Upsert customer
        $customer = Customer::updateOrCreate(
            ['phone' => $this->normalizePhone($leadData['Phone'] ?? null)], // Match by email (unique identifier)
            [
                'name' => $leadData['Name'] ?? 'Unknown',
                'email' => $leadData['Email'],
                'company_id' => $assignedUser->company_id,
                'assigned_to' => $assignedUser->id,
                'status' => 'active',
		'old_owner_id'=> $oldOwnerId,
                'notes' => "Bitrix24 Lead ID: {$leadData['LeadId']}",
            ]
        );

        Log::info("Customer upserted for lead {$leadId}", [
            'customer_id' => $customer->id,
            'user_id' => $assignedUser->id,
            'company_id' => $assignedUser->company_id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Lead {$leadId} processed successfully",
            'customer_id' => $customer->id,
            'action' => $customer->wasRecentlyCreated ? 'created' : 'updated',
        ]);
    }

    private function handleLeadDelete(int $leadId): \Illuminate\Http\JsonResponse
    {
        // Find customer by Bitrix Lead ID in notes
        $customer = Customer::where('notes', 'like', "%Bitrix24 Lead ID: {$leadId}%")
            ->first();

        if (!$customer) {
            Log::warning("No customer found for deleted lead {$leadId}");
            return response()->json([
                'status' => 'skipped',
                'message' => "No customer found for lead {$leadId}",
            ], 200);
        }

        $customer->delete(); // Soft delete

        Log::info("Customer soft-deleted for lead {$leadId}", [
            'customer_id' => $customer->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Lead {$leadId} deleted, customer soft-deleted",
            'customer_id' => $customer->id,
        ]);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 10) {
            $phone = '91' . $phone;
	}

	if (!preg_match('/^91\d{10}$/', $phone)) {
            return null; // or throw an exception if you prefer
        }

        return $phone;
    }
   
    public function metaResponse(Request $request){
	\Log::info($request->all());
	return $request->all();
    }
}
