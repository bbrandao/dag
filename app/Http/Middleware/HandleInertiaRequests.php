<?php

namespace App\Http\Middleware;

use App\Services\PushTransport;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(private readonly PushTransport $push) {}

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'authenticated' => fn () => $request->user() !== null,
            'viewer' => function () use ($request): ?array {
                $user = $request->user();

                return $user === null ? null : [
                    ...$user->only(['id', 'name', 'role', 'relationship']),
                    'whatsappNotices' => (bool) $user->whatsapp_notices,
                    'pushPublicKey' => $this->push->configured() ? config('dag.web_push.publicKey') : null,
                ];
            },
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
