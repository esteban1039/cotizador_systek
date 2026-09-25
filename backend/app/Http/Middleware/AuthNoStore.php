<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('api/v1/auth/*', 'api/v1/admin/history', 'api/v1/admin/history/*', 'api/v1/admin/company')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
