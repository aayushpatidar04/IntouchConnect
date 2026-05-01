<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCompanyIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->company && !$user->company->is_active) {
            auth()->logout();

            return redirect()->route('login')
                ->with('error', 'Your company has been deactivated. Please contact support.');

        }

        return $next($request);
    }
}
