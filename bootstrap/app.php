<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureProfessor;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\CheckLicense;
use App\Http\Middleware\Authenticate;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: base_path('routes/api.php'),
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/admin.php'));
            
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/test.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reemplazar el middleware de autenticación por defecto con el nuestro personalizado
        $middleware->replace(
            \Illuminate\Auth\Middleware\Authenticate::class,
            Authenticate::class
        );
        
        // Alias de middleware de rutas
        $middleware->alias([
            'professor' => EnsureProfessor::class,
            'admin' => EnsureAdmin::class,
            'license' => CheckLicense::class,
        ]);
        
        // Aplicar middleware de licencia globalmente a todas las rutas API
        $middleware->api(append: [
            CheckLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Configurar manejo de excepciones para rutas API
        $exceptions->render(function (Throwable $e, $request) {
            // ✅ IMPORTANTE: Si es una ruta API o cliente espera JSON, SIEMPRE devolver JSON
            if ($request->is('api/*') || $request->expectsJson()) {
                
                // Manejar ValidationException específicamente
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $e->errors()
                    ], 422);
                }
                
                // ✅ Manejar errores de autenticación (NUNCA redirect en API)
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthenticated.'
                    ], 401);
                }
                
                // Manejar errores de autorización
                if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Forbidden'
                    ], 403);
                }
                
                // Manejar errores 404
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Not found'
                    ], 404);
                }
                
                // Manejar errores 405 (Method Not Allowed)
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Method not allowed'
                    ], 405);
                }
                
                // Manejar errores HTTP genéricos
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'HTTP error'
                    ], $e->getStatusCode());
                }
                
                // Para otros errores, devolver error genérico
                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                
                return response()->json([
                    'success' => false,
                    'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                    'debug' => config('app.debug') ? [
                        'exception' => class_basename($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ] : null
                ], $statusCode);
            }
            
            // Para rutas web, usar el manejo por defecto
            return null;
        });
    })->create();
