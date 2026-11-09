<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        if (getenv('DAG_TRUST_PROXY') === 'true') {
            $middleware->trustProxies(
                at: '*',
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['active.account' => ActiveAccount::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/app');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'phone', 'birth_date']);
    })
    ->create();
