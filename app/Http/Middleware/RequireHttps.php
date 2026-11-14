<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('aop.enforce_https') && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 308);
        }

        $secure = $request->isSecure();
        $response = $next($request);

        if ($secure) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
