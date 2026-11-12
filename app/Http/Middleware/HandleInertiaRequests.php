<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'authenticated' => fn () => $request->user() !== null,
            'viewer' => fn () => $request->user()?->only(['id', 'name']),
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
