# ✅ Implementación: Fix de Autenticación en API

**Fecha:** 31 de Enero, 2026  
**Problema:** `Route [login] not defined` cuando no está autenticado en endpoint API  
**Solución:** Middleware personalizado que evita redirecciones en requests API

---

## 📋 Resumen de Cambios

### 1️⃣ **Crear Middleware Personalizado**
**Archivo:** `app/Http/Middleware/Authenticate.php`

```php
<?php
namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        // API requests: retornar null para disparar AuthenticationException (→ JSON 401)
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }
        // Web requests: redirigir a login normalmente
        return route('login');
    }
}
```

**Razón:** Reemplaza el middleware por defecto de Laravel que intentaba redirigir a `route('login')` incluso en APIs.

---

### 2️⃣ **Registrar Middleware en `bootstrap/app.php`**

**Cambio 1 - Importar:**
```php
use App\Http\Middleware\Authenticate;
```

**Cambio 2 - Registrar en `withMiddleware()`:**
```php
$middleware->replace(
    \Illuminate\Auth\Middleware\Authenticate::class,
    Authenticate::class
);
```

**Razón:** Reemplazar el middleware de autenticación por defecto de Laravel con el nuestro.

---

### 3️⃣ **Mejorar Exception Handler en `bootstrap/app.php`**

Las excepciones de autenticación ahora devuelven respuestas JSON estándar:

```php
// Autenticación fallida
if ($e instanceof \Illuminate\Auth\AuthenticationException) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthenticated',
        'error' => 'authentication_required'
    ], 401);
}

// Autorización fallida
if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
    return response()->json([
        'success' => false,
        'message' => 'Forbidden',
        'error' => 'authorization_failed'
    ], 403);
}
```

---

## 🔍 Flujo de Autenticación

```
Request a /api/admin/profesores/6/socios (SIN token)
         ↓
Llega al middleware auth:sanctum
         ↓
Usuario no autenticado → lanza AuthenticationException
         ↓
Nuestro middleware Authenticate.redirectTo() es llamado
         ↓
Detecta que es API request → retorna null
         ↓
AuthenticationException es propagada
         ↓
Exception handler en bootstrap/app.php la captura
         ↓
Devuelve: HTTP 401 + JSON {"success": false, "message": "Unauthenticated", ...}
         ↓
Frontend recibe JSON 401 (sin redirección a /login)
```

---

## ✅ Verificación

### Rutas Protegidas
```php
// routes/api.php - línea ~90
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('admin')->middleware('admin')->group(function () {
        // ✅ Esta ruta está CORRECTAMENTE protegida:
        Route::get('profesores/{profesor}/socios', [ProfesorSocioController::class, 'sociosPorProfesor']);
        Route::post('profesores/{profesor}/socios', [ProfesorSocioController::class, 'syncSocios']);
    });
});
```

### Guard Correcto
- ✅ Usa `auth:sanctum` (guard API, no `auth` que es web)
- ✅ Middleware `admin` verifica roles
- ✅ Nunca redirige a web routes

### Respuestas JSON
- ✅ 401 Unauthenticated
- ✅ 403 Forbidden
- ✅ Nunca HTML de redirección

---

## 🧪 Testing Quick

### SIN Autenticación (401):
```bash
curl -i -H "Accept: application/json" \
  https://vm-gym-api.test/api/admin/profesores/6/socios
```

**Respuesta:**
```json
HTTP/1.1 401 Unauthorized

{
  "success": false,
  "message": "Unauthenticated",
  "error": "authentication_required"
}
```

### CON Token Admin (200):
```bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGc..."

curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     https://vm-gym-api.test/api/admin/profesores/6/socios
```

**Respuesta:**
```json
HTTP/1.1 200 OK

{
  "ok": true,
  "data": {
    "current_page": 1,
    "data": [...],
    "total": 100,
    "per_page": 50
  }
}
```

---

## 📚 Documentación Asociada

1. **[API_AUTH_FIX_TESTING.md](API_AUTH_FIX_TESTING.md)**
   - Ejemplos detallados con curl
   - Matriz de respuestas de error
   - Checklist de testing

2. **[FRONTEND_AUTH_BEST_PRACTICES.md](FRONTEND_AUTH_BEST_PRACTICES.md)**
   - Cómo evitar reintentos infinitos
   - Hooks de React
   - Exponential backoff para reintentos

---

## 🚀 Deploy

### Pasos:
1. Commitear cambios:
   ```bash
   git add app/Http/Middleware/Authenticate.php bootstrap/app.php
   git commit -m "fix: evitar redirección a /login en requests API no autenticadas"
   ```

2. Deploy a producción (sin pasos adicionales, no requiere migraciones)

3. Testing post-deploy:
   ```bash
   # Probar 401
   curl -H "Accept: application/json" https://api.ejemplo.com/api/admin/profesores/6/socios
   
   # Debe devolver HTTP 401 + JSON, NO redirección
   ```

---

## ⚠️ Posibles Problemas

| Problema | Causa | Solución |
|----------|-------|----------|
| Aún redirige a `/login` | Middleware no registrado correctamente | Verificar que `$middleware->replace()` está en bootstrap/app.php |
| Errores 500 | Exception handler no configurado | Verificar que exception handler está en bootstrap/app.php |
| Status 403 indefinidamente | Rol de usuario incorrecto | Verificar `$user->isAdmin()` en User model |
| No recibe token | Frontend no envía Authorization header | Verificar `Authorization: Bearer <token>` en request |

---

## ✨ Beneficios

- ✅ API devuelve siempre JSON, nunca HTML/redirecciones
- ✅ Errores 401/403 claros para el frontend
- ✅ Rutas web siguen funcionando (redireccionan a login normalmente)
- ✅ Compatible con Sanctum, JWT, cualquier guard
- ✅ No requiere cambios en rutas existentes
- ✅ No requiere migraciones de base de datos

---

## 📞 Soporte

Errores comunes durante testing:

```bash
# ❌ Si ves este error:
# "Route [login] not defined"

# ✅ Significa que el middleware no está registrado correctamente.
# Verificar que bootstrap/app.php tiene:
# $middleware->replace(\Illuminate\Auth\Middleware\Authenticate::class, Authenticate::class);
```

```bash
# ✅ Respuesta correcta después del fix:
# HTTP 401 con JSON, sin error de route

curl -v https://api.test/api/admin/profesores/6/socios
# < HTTP/1.1 401 Unauthorized
# < Content-Type: application/json
# {"success": false, "message": "Unauthenticated", ...}
```

---

**Implementado y testeado:** ✅  
**Listo para producción:** ✅

