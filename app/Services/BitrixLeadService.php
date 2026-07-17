<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BitrixLeadService
{
    /**
     * Fetch a lead from the Arihant API.
     *
     * @throws RequestException
     */
    public function fetchLead(int $leadId): array
    {
        $baseUrl = rtrim(
            (string) config('services.bitrix_leads.url'),
            '/'
        );

        $username = config(
            'services.bitrix_leads.username'
        );

        $password = config(
            'services.bitrix_leads.password'
        );

        if (!$username || !$password) {
            throw new RuntimeException(
                'Bitrix lead API credentials are not configured.'
            );
        }

        $response = Http::withBasicAuth(
                $username,
                $password
            )
            ->acceptJson()
            ->timeout(
                config('services.bitrix_leads.timeout', 20)
            )
            ->retry(
                2,
                500,
                throw: false
            )
            ->get("{$baseUrl}/{$leadId}");

        if (!$response->successful()) {
            Log::error('Bitrix lead API request failed', [
                'lead_id' => $leadId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $response->throw();
        }

        $lead = $response->json();

        if (!is_array($lead)) {
            throw new RuntimeException(
                "Invalid response received for lead {$leadId}."
            );
        }

        Log::info('Bitrix lead fetched from API', [
            'lead_id' => $leadId,
            'assigned_by_id' =>
                $lead['AssignedById'] ?? null,
        ]);

        return $lead;
    }

    /**
     * Fetch and save/update a Bitrix lead.
     */
    public function fetchAndSync(
        int $leadId,
        string $source = 'manual'
    ): array {
        $lead = $this->fetchLead($leadId);

        return $this->syncLead(
            lead: $lead,
            source: $source
        );
    }

    /**
     * Save/update already fetched lead data.
     */
    public function syncLead(
        array $lead,
        string $source = 'webhook'
    ): array {
        $this->validateLead($lead);

        $leadId = (int) $lead['LeadId'];
        $assignedById = (int) $lead['AssignedById'];

        $assignedUser = User::query()
            ->where(
                'bitrix_user_id',
                $assignedById
            )
            ->first();
	
	$observerUser = $this->resolveObserverUser(
	    $lead['Observers'] ?? null
	);

        if (!$assignedUser && !$observerUser) {
            throw ValidationException::withMessages([
                'lead_id' => [
                    "No local user is mapped to Bitrix AssignedById {$assignedById}.",
                ],
            ]);
        }

        if (!$assignedUser?->company_id && !$observerUser?->company_id) {
            throw ValidationException::withMessages([
                'lead_id' => [
                    "Assigned user and old Owner {$assignedUser?->name} does not have a company.",
                ],
            ]);
        }

        $normalizedPhone = $this->normalizePhone(
            $lead['Phone'] ?? null
        );

        if (!$normalizedPhone) {
            throw ValidationException::withMessages([
                'lead_id' => [
                    'The lead does not contain a valid Indian mobile number.',
                ],
            ]);
        }

	return DB::transaction(function () use (
	    $lead,
	    $leadId,
	    $assignedById,
	    $assignedUser,
	    $observerUser,
	    $normalizedPhone,
	    $source
	) {
	    
	    $customerByLeadId = Customer::withoutGlobalScopes()
	        ->where('bitrix_lead_id', $leadId)->first();

	    $customerByPhone = Customer::withoutGlobalScopes()
	        ->withTrashed()
        	->where('phone', $normalizedPhone)
	        ->first();

	    if (
	        $customerByLeadId &&
	        $customerByPhone &&
        	$customerByLeadId->id !== $customerByPhone->id
	    ) {
        	throw ValidationException::withMessages([
	            'lead_id' => [
        	        "Bitrix Lead {$leadId} is linked to customer " .
	                "#{$customerByLeadId->id}, but phone {$normalizedPhone} " .
                	"belongs to customer #{$customerByPhone->id}. " .
        	        'The records were not modified.',
	            ],
        	]);
	    }

	    $customer = $customerByLeadId
	        ?? $customerByPhone;

	    $previousAssignedTo = $customer?->assigned_to;
	    $previousCompanyId = $customer?->company_id;
	    $previousOldOwnerId = $customer?->old_owner_id;

	    $assignedTo = $assignedUser?->id;
	    $oldOwnerId = $observerUser?->id;

	    $companyId = $assignedUser?->company_id
	        ?? $observerUser?->company_id
        	?? $customer?->company_id;

	    $values = [
	        'bitrix_lead_id' => $leadId,

        	'bitrix_assigned_by_id' =>
	            $assignedById,

        	'name' => trim(
	            $lead['Name'] ?? ''
        	) ?: 'Unknown',

	        'email' => $this->normalizeEmail(
        	    $lead['Email'] ?? null
	        ),

        	'phone' => $normalizedPhone,

	        'assigned_to' => $assignedTo,

	        'old_owner_id' => $oldOwnerId,

        	'company_id' => $companyId,

	        'status' => 'active',

        	'bitrix_created_at' =>
	            $this->parseBitrixDate(
        	        $lead['CreatedDate'] ?? null
	            ),

        	'bitrix_synced_at' => now(),

	        'notes' =>
        	    "Bitrix24 Lead ID: {$leadId}",
	    ];

	    if ($customer) {
        	$customer->fill($values);
	        $customer->save();

        	if (
	            method_exists($customer, 'trashed') &&
        	    $customer->trashed()
	        ) {
        	    $customer->restore();
	        }

        	$action = 'updated';
	    } else {
	        if (!$companyId) {
        	    throw ValidationException::withMessages([
	                'lead_id' => [
                	    "No company could be resolved for Bitrix lead {$leadId}. AssignedById {$assignedById} and its observers are not mapped to CRM users.",
        	        ],
	            ]);
        	}

	        $customer = Customer::create($values);
        	$action = 'created';
	    }

	    Log::info('Bitrix customer synchronized', [
	        'source' => $source,
        	'lead_id' => $leadId,
	        'customer_id' => $customer->id,

	        'bitrix_assigned_by_id' => $assignedById,

        	'assigned_user_found' =>
	            (bool) $assignedUser,

        	'observer_user_found' =>
	            (bool) $observerUser,

        	'previous_assigned_to' =>
	            $previousAssignedTo,

        	'new_assigned_to' =>
	            $assignedTo,

        	'previous_old_owner_id' =>
	            $previousOldOwnerId,

        	'new_old_owner_id' =>
	            $oldOwnerId,

        	'previous_company_id' =>
	            $previousCompanyId,

        	'new_company_id' =>
	            $companyId,

        	'action' => $action,
	    ]);

	    return [
        	'customer' => $customer->fresh([
	            'assignedTo',
        	    'company',
	        ]),

        	'action' => $action,

	        'assignment_changed' =>
        	    (int) $previousAssignedTo !==
	            (int) $assignedTo,

        	'old_owner_changed' =>
	            (int) $previousOldOwnerId !==
        	    (int) $oldOwnerId,

	        'company_changed' =>
	            (int) $previousCompanyId !==
	            (int) $companyId,

	        'assigned_user_missing' =>
        	    !$assignedUser,

	        'observer_used_as_owner' =>
        	    !$assignedUser &&
	            (bool) $observerUser,
	    ];
        });
    }

    private function validateLead(array $lead): void
    {
        $errors = [];

        if (empty($lead['LeadId'])) {
            $errors['lead_id'][] =
                'LeadId is missing from the API response.';
        }

        if (empty($lead['AssignedById'])) {
            $errors['lead_id'][] =
                'AssignedById is missing from the API response.';
        }

        if ($errors) {
            throw ValidationException::withMessages(
                $errors
            );
        }
    }

    private function normalizePhone(
        mixed $phone
    ): ?string {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace(
            '/\D+/',
            '',
            (string) $phone
        );

        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        } elseif (
            strlen($digits) === 11 &&
            str_starts_with($digits, '0')
        ) {
            $digits = '91' . substr($digits, 1);
        } elseif (
            strlen($digits) === 13 &&
            str_starts_with($digits, '091')
        ) {
            $digits = substr($digits, 1);
        } elseif (
            strlen($digits) === 14 &&
            str_starts_with($digits, '0091')
        ) {
            $digits = substr($digits, 2);
        }

        return preg_match(
            '/^91[6-9]\d{9}$/',
            $digits
        )
            ? $digits
            : null;
    }

    private function normalizeEmail(
        mixed $email
    ): ?string {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        return filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
            ? strtolower($email)
            : null;
    }

    /**
     * The API may return one observer ID, multiple IDs,
     * or a delimited string. Use the first matching user.
     */
    private function resolveObserverUser(
        mixed $observers
    ) {
        if (empty($observers)) {
            return null;
        }

        $observerIds = is_array($observers)
            ? $observers
            : preg_split(
                '/[,;|]+/',
                (string) $observers
            );

        $observerIds = collect($observerIds)
            ->map(
                fn ($value) =>
                    trim((string) $value)
            )
            ->filter()
            ->values();

        if ($observerIds->isEmpty()) {
            return null;
        }

        return User::query()
            ->whereIn(
                'bitrix_user_id',
                $observerIds->all()
            )
            ->first();
    }

    private function parseBitrixDate(
        mixed $date
    ): ?Carbon {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
