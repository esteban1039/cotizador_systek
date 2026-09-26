<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La API solo debe atenderse a través del proxy BFF: el navegador nunca habla con ella.
 * Con el secreto configurado exige `X-BFF-Secret` (404 si falta o no coincide, sin revelar la ruta) y,
 * únicamente entonces, confía en `X-Forwarded-For`/`-Proto` para que `$request->ip()` sea la del cliente
 * real y no la del proxy. Sin secreto: en producción responde 503; en otros entornos no exige nada.
 */
final class VerifyBffSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('security.bff_secret');
        if ($secret === '') {
            abort_if(app()->isProduction(), 503, 'La API no está configurada para recibir tráfico.');

            return $next($request);
        }

        if (! hash_equals($secret, (string) $request->header('X-BFF-Secret'))) {
            abort(404);
        }

        Request::setTrustedProxies(
            [(string) $request->server->get('REMOTE_ADDR')],
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        return $next($request);
    }
}
