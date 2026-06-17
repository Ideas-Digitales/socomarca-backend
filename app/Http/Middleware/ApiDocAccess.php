<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiDocAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('api-doc.login');
        }

        if (!auth()->user()->can('api-doc.read.all')) {
            abort(403, 'No tienes permiso para acceder a la documentación de la API.');
        }

        return $next($request);
    }
}
