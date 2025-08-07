<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Excluir la ruta de retorno de Webpay
        if ($request->is('api/webpay/return')) {
            Log::info('ForceJsonResponse: Excluyendo ruta de Webpay return');
            return $next($request);
        }

        // Excluir rutas de exportación
        if (
            $request->is('api/orders/reports/*/export') ||
            $request->is('api/reports/*/export') ||
            $request->is('api/*/exports') ||
            $request->is('api/exports/*')
        ) {
            return $next($request);
        }
        
        if (!$request->wantsJson()) {
            Log::warning('ForceJsonResponse: Intento de acceso sin JSON', [
                'url' => $request->fullUrl(),
                'method' => $request->method()
            ]);
            return response()->json([
                'message' => 'Response must be JSON'
            ], Response::HTTP_NOT_ACCEPTABLE);
        }
        return $next($request);
    }
}