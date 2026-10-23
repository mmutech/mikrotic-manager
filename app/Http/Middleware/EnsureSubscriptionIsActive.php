<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasActiveSubscription()) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Your subscription has expired. Renew it to continue using the platform.');
        }

        return $next($request);
    }
}
