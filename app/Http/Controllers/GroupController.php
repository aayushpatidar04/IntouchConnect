<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Group;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GroupController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Base groups query: only groups created by this user
        $groupsQuery = Group::withCount(['customers'])
            ->with(['customers:id,name,phone', 'creator:id,name'])
            ->where('created_by', $user->id);

        // Base customers query
        $customersQuery = Customer::select('id', 'name', 'phone')
            ->where('company_id', $user->company_id);

        // Restrict customers if user is executive
        if ($user->hasRole('executive')) {
            $customersQuery->where('assigned_to', $user->id);
        }

        return Inertia::render('Groups/Index', [
            'groups' => $groupsQuery->paginate(20),
            'customers' => $customersQuery->get(),
        ]);
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'customers' => 'array',
        ]);

        $group = Group::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $group->customers()->sync($validated['customers'] ?? []);

        return redirect()->route('groups.index');
    }

    public function update(Request $request, Group $group)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'customers' => 'array',
        ]);

        $group->update($validated);
        $group->customers()->sync($validated['customers'] ?? []);

        return redirect()->route('groups.index');
    }

    public function destroy(Group $group)
    {
        $group->delete();
        return redirect()->route('groups.index');
    }

    public function list(Request $request)
    {
        $perPage = (int) $request->input('per_page', 50);

        $user = auth()->user();
        $query = Group::with('customers')->where('created_by', $user->id)->orderBy('created_at', 'desc');

        $groups = $query->paginate($perPage);

        return response()->json($groups);
    }
}

