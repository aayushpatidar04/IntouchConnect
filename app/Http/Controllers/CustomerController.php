<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = auth()->user();
        $query = Customer::with(['assignedTo', 'latestMessage'])->withCount('documents');

        // Executives only see their own assigned customers
        if ($user->hasRole('executive')) {
            $query->where('assigned_to', $user->id);
        }
        // Admin + auditor see all customers in their company (CompanyScope handles this)

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name',    'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('company', 'like', "%{$request->search}%");
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->assigned_to) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $customers = $query->orderByDesc('last_contacted_at')->paginate(20)->withQueryString();

        // Executives list — scoped to same company
        $executives = User::where('company_id', $user->company_id)
            ->role('executive')
            ->select('id', 'name')
            ->get();

        return Inertia::render('Customers/Index', [
            'customers'  => $customers,
            'executives' => $executives,
            'filters'    => $request->only(['search', 'status', 'assigned_to']),
        ]);
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        AuditService::log('customer.viewed', $customer);

        $messages = $customer->messages()
            ->with(['sentBy', 'document'])
            ->orderBy('created_at')
            ->get();

        $documents = $customer->documents()
            ->with('uploadedBy')
            ->orderByDesc('created_at')
            ->get();

        $executives = User::where('company_id', auth()->user()->company_id)
            ->role('executive')
            ->select('id', 'name')
            ->get();

        return Inertia::render('Customers/Show', [
            'customer'   => $customer->load('assignedTo'),
            'messages'   => $messages,
            'documents'  => $documents,
            'executives' => $executives,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'phone'       => 'required|string|max:20',
            'email'       => 'nullable|email|max:191',
            'company'     => 'nullable|string|max:191',
            'notes'       => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'status'      => 'in:active,inactive,blocked',
        ]);

        // Phone must be unique within this company (not globally)
        $phone = preg_replace('/\D/', '', $data['phone']);
        $exists = Customer::where('phone', $phone)->exists();
        if ($exists) {
            return back()->withErrors(['phone' => 'This phone number already exists.']);
        }

        $data['phone']      = $phone;
        $data['company_id'] = auth()->user()->company_id;

        $customer = Customer::create($data);
        AuditService::log('customer.created', $customer, [], $data);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer created.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'phone'       => "required|string|max:20",
            'email'       => 'nullable|email|max:191',
            'company'     => 'nullable|string|max:191',
            'notes'       => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'status'      => 'in:active,inactive,blocked',
        ]);

        $phone = preg_replace('/\D/', '', $data['phone']);
        $duplicate = Customer::where('phone', $phone)->where('id', '!=', $customer->id)->exists();
        if ($duplicate) {
            return back()->withErrors(['phone' => 'This phone number already exists.']);
        }
        $data['phone'] = $phone;

        $old = $customer->only(array_keys($data));
        $customer->update($data);
        AuditService::log('customer.updated', $customer, $old, $data);

        return back()->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);
        AuditService::log('customer.deleted', $customer);
        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }
}