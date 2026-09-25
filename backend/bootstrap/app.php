<?php

use App\Http\Middleware\AuthNoStore;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AuthNoStore::class);
        $middleware->alias(['role' => RequireRole::class]);
        // API authentication errors must not resolve a web login route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response) {
            if (request()->is('api/v1/auth/*', 'api/v1/admin/history', 'api/v1/admin/history/*', 'api/v1/admin/company')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['bank_account', 'bank_account.account_number', 'bank_account.account_number_confirmation']);
    })->create();
