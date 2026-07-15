<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'phone'          => $user->phone,
                    'avatar_url'     => $user->avatar_url,
                    'company_id'     => $user->company_id,
                    'company_name'   => $user->company?->name,
                    // Flat role array — used in Vue as:
                    // $page.props.auth.user.roles.includes('admin')
                    'roles'          => $user->getRoleNames()->toArray(),
                    // Convenience booleans — avoids repeating includes() checks in templates
                    'is_super_admin' => $user->isSuperAdmin(),
                    'is_admin'       => $user->isCompanyAdmin(),
                ] : null,
            ],
            'whatsapp_session_id' => $user?->company?->slug,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
	    'accessibleCompanies' => function () {
                if (!auth()->check()) {
                    return [];
                }
                return auth()->user()
                    ->accessibleCompanies()
                    ->select('companies.id', 'companies.name')
                    ->get();
            },
        ]);
    }
}
