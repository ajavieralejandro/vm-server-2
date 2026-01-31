<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo(Request $request): ?string
    {
        // Si la solicitud espera JSON o es una ruta API,
        // retorna null para NO redirigir. Esto dispara una AuthenticationException
        // que será capturada por el exception handler en bootstrap/app.php
        // y devolverá una respuesta JSON 401
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // Para rutas web, redirigir a login normalmente
        return route('login');
    }
}
