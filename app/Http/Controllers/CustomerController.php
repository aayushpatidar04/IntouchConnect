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
        $query = Customer::with(['assignedTo', 'latestMessage'])
            ->withCount('documents');

        // Role-based filtering
        if (auth()->user()->hasRole('executive')) {
            $query->where('assigned_to', auth()->id());
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
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

        return Inertia::render('Customers/Index', [
            'customers'  => $customers,
            'executives' => User::role('executive')->select('id', 'name')->get(),
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

        return Inertia::render('Customers/Show', [
            'customer'   => $customer->load('assignedTo'),
            'messages'   => $messages,
            'documents'  => $documents,
            'executives' => User::role('executive')->select('id', 'name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'phone'       => 'required|string|max:20|unique:customers,phone',
            'email'       => 'nullable|email|max:191',
            'company'     => 'nullable|string|max:191',
            'notes'       => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'status'      => 'in:active,inactive,blocked',
        ]);

        $customer = Customer::create($data);
        AuditService::log('customer.created', $customer, [], $data);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer created.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'phone'       => "required|string|max:20|unique:customers,phone,{$customer->id}",
            'email'       => 'nullable|email|max:191',
            'company'     => 'nullable|string|max:191',
            'notes'       => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'status'      => 'in:active,inactive,blocked',
        ]);

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