# 🔧 Fix: API 401 Unauthenticated - Sin Redirección a Login

## ❌ Problema
```
Route [login] not defined
Stacktrace: Illuminate\Auth\Middleware\Authenticate->redirectTo() -> route('login')
```

La API intentaba redirigir a login en vez de devolver JSON 401.

---

## ✅ Solución Implementada

### 1. Archivo Modificado: `app/Http/Middleware/Authenticate.php`

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

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
        if ($request->route() && route('login', [], false)) {
            return route('login');
        }

        return null;
    }
}
```

**Cambios clave:**
- ✅ Devuelve `null` siempre para rutas `api/*`
- ✅ Devuelve `null` siempre si cliente espera JSON
- ✅ Para web, valida que la ruta 'login' existe antes de retornarla
- ✅ Usa `route('login', [], false)` para no lanzar excepción si no existe

---

### 2. Archivo Modificado: `bootstrap/app.php`

En la sección `->withExceptions()`, el manejador de excepciones ya captura `AuthenticationException`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    // Configurar manejo de excepciones para rutas API
    $exceptions->render(function (Throwable $e, $request) {
        // ✅ IMPORTANTE: Si es una ruta API o cliente espera JSON, SIEMPRE devolver JSON
        if ($request->is('api/*') || $request->expectsJson()) {
            
            // ... validaciones ...
            
            // ✅ Manejar errores de autenticación (NUNCA redirect en API)
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }
            
            // ... más validaciones ...
        }
        
        // Para rutas web, usar el manejo por defecto
        return null;
    });
})->create();
```

**Cambios clave:**
- ✅ Captura `AuthenticationException` específicamente
- ✅ Devuelve JSON 401 con estructura `{ "success": false, "message": "Unauthenticated." }`
- ✅ Valida que es una ruta API antes de devolver JSON
- ✅ Nunca intenta hacer redirect en API

---

## 🚀 Comandos de Limpieza (EJECUTAR EN ESTE ORDEN)

```bash
# 1. Limpiar cache de rutas
php artisan route:clear

# 2. Limpiar cache de configuración
php artisan config:clear

# 3. Limpiar cache general
php artisan cache:clear

# 4. Limpiar cache de vistas
php artisan view:clear

# 5. Opcional: Compilar config en producción
php artisan config:cache

# 6. Opcional: Cachear rutas en producción
php artisan route:cache
```

---

## ✅ Verificación con cURL

### Test 1: Sin token (sin autenticación)
```bash
curl -X GET "http://localhost:8000/api/admin/socios" \
  -H "Accept: application/json"
```

**Respuesta esperada: 401**
```json
{
  "success": false,
  "message": "Unauthenticated."
}
```

✅ **NO debe devolver**: 500, No debe redireccionar, No debe mencionar "route('login')"

---

### Test 2: Con token inválido
```bash
curl -X GET "http://localhost:8000/api/admin/socios" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer invalid_token_here"
```

**Respuesta esperada: 401**
```json
{
  "success": false,
  "message": "Unauthenticated."
}
```

---

### Test 3: Con token válido pero sin rol admin
```bash
# Primero obtener token de profesor
TOKEN=$(curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"profesor@test.com","password":"password"}' \
  | jq -r '.data.token')

# Luego intentar acceder a endpoint admin
curl -X GET "http://localhost:8000/api/admin/socios" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

**Respuesta esperada: 403**
```json
{
  "success": false,
  "message": "Forbidden"
}
```

---

### Test 4: Con token válido y rol correcto
```bash
# Token de admin
ADMIN_TOKEN=$(curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"password"}' \
  | jq -r '.data.token')

# Acceder correctamente
curl -X GET "http://localhost:8000/api/admin/socios" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

**Respuesta esperada: 200**
```json
{
  "ok": true,
  "data": [
    {...}
  ]
}
```

---

## 🔍 Debugging - Si Sigue Fallando

### Verificar que los cambios se aplicaron:
```bash
# 1. Ver contenido del middleware
grep -n "redirectTo" app/Http/Middleware/Authenticate.php

# 2. Ver contenido del bootstrap
grep -n "AuthenticationException" bootstrap/app.php

# 3. Listar rutas disponibles
php artisan route:list | grep login
```

### Si aún retorna error redirect:
```bash
# 1. Verificar que la aplicación es Laravel 11
composer show laravel/framework

# 2. Verificar el archivo de configuración auth
cat config/auth.php | grep -A 5 "guards"

# 3. Verificar el ambiente
cat .env | grep APP_ENV
cat .env | grep APP_DEBUG
```

---

## 📋 Resumen de Cambios

| Archivo | Cambio |
|---------|--------|
| `app/Http/Middleware/Authenticate.php` | ✅ Validar antes de llamar `route('login')`, retornar `null` para API |
| `bootstrap/app.php` | ✅ Exception Handler captura `AuthenticationException` y devuelve JSON 401 |

---

## 🎯 Resultado Final Esperado

Después de aplicar estos cambios:

- ✅ Requests sin token a `/api/*` → **401 JSON** (no redirect)
- ✅ Requests con token inválido → **401 JSON** (no redirect)
- ✅ Requests a `/api/*` sin rol correcto → **403 JSON** (no redirect)
- ✅ Requests a `/api/*` válidas → **200/201/etc** con respuesta JSON
- ✅ NO aparece error "Route [login] not defined"
- ✅ NO hay redirecciones a `/login`
- ✅ Todos los errores devuelven JSON estructurado

---

## 📞 Soporte

Si necesitas más detalles sobre algún aspecto específico:

1. **Autenticación con Sanctum**: Ver `config/sanctum.php`
2. **Middlewares personalizados**: Ver `app/Http/Middleware/`
3. **Rutas API**: Ver `routes/api.php`
4. **Exception Handling**: Ver `bootstrap/app.php` en la sección `withExceptions()`

