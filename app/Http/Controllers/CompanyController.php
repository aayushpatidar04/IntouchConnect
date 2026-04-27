<?php
namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Company::class); // admin only
        return Inertia::render('Companies/Index', [
            'companies' => Company::withCount(['users', 'customers'])->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'slug'           => 'required|string|max:50|unique:companies,slug|regex:/^[a-z0-9\-]+$/',
            'gateway_url'    => 'required|url',
            'gateway_secret' => 'required|string|min:16',
        ]);
        Company::create($data);
        return back()->with('success', 'Company created.');
    }

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'gateway_url'    => 'required|url',
            'gateway_secret' => 'required|string|min:16',
            'is_active'      => 'boolean',
        ]);
        $company->update($data);
        return back()->with('success', 'Company updated.');
    }
}