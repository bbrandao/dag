<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $session = $request->session();

        if ($user->active && Auth::guard('web')->viaRemember() && ! $session->has('account_version')) {
            $session->put('account_version', $user->session_version);
        }

        if (! $user->active || $session->get('account_version') !== $user->session_version) {
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();
            Inertia::clearHistory();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
