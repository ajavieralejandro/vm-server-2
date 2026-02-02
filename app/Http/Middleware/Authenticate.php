<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     * 
     * Para API: NUNCA retornar una ruta de redirect, el Exception Handler manejará
     * la AuthenticationException y devolverá JSON 401.
     * 
     * Para web: Retornar ruta de login si existe.
     */
    protected function redirectTo(Request $request): ?string
    {
        // ✅ NUNCA redireccionar en API
        // - $request->expectsJson(): Cliente espera JSON (Accept: application/json)
        // - $request->is('api/*'): Ruta comienza con /api/
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // Para rutas web, solo redirigir si la ruta 'login' existe
        if ($request->route() && Route::has('login')) {
            return route('login');
        }

        return null;
    }
}
