<?php

namespace App\Http\Middleware;

use App\Application\Identity\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMfaEnrollment
{
    public function __construct(private MfaPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = [
            'GET api/v1/auth/me', 'POST api/v1/auth/logout', 'POST api/v1/auth/password',
            'GET api/v1/auth/mfa', 'POST api/v1/auth/mfa/setup', 'POST api/v1/auth/mfa/confirm',
        ];
        if ($this->policy->enrollmentRequired($request->user()) && ! in_array($request->method().' '.$request->path(), $allowed, true)) {
            return response()->json([
                'message' => 'Activa la verificación en dos pasos para continuar.',
                'code' => 'mfa_enrollment_required',
            ], 403)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
