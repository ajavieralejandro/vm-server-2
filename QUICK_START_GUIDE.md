# ⚡ QUICK START: Guía Rápida para Developers

## 🚀 TL;DR - En 30 Segundos

**Problema:** API redirecciona a `/login` cuando no está autenticado  
**Solución:** Creamos un middleware que devuelve JSON 401 en lugar de redirigir  
**Estado:** ✅ IMPLEMENTADO

---

## 📂 Qué Cambió

### Archivo Nuevo
```
app/Http/Middleware/Authenticate.php ← Créalo con el contenido siguiente
```

### Archivo Modificado
```
bootstrap/app.php ← Agrega las líneas siguientes en withMiddleware()
```

---

## 📋 Quick Checklist

- [ ] Leer `app/Http/Middleware/Authenticate.php` (nuevo)
- [ ] Verificar que `bootstrap/app.php` tiene `$middleware->replace(...)`
- [ ] Probar: `curl -H "Accept: application/json" https://api.test/api/admin/profesores/6/socios`
- [ ] Debe devolver JSON 401 (no error de route)

---

## 🧪 Tests Mínimos

```bash
# Test 1: Sin token → JSON 401 ✓
curl -H "Accept: application/json" https://api.test/api/admin/profesores/6/socios

# Test 2: Con token admin → JSON 200 ✓
TOKEN=$(curl -s -X POST https://api.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"pass"}' | jq -r '.data.token')

curl -H "Authorization: Bearer $TOKEN" https://api.test/api/admin/profesores/6/socios
```

---

## 📖 Documentación Completa

| Doc | Para Qué |
|-----|----------|
| `IMPLEMENTACION_FIX_AUTH.md` | Entender qué se hizo y por qué |
| `API_AUTH_FIX_TESTING.md` | Testing con curl detallado |
| `FRONTEND_AUTH_BEST_PRACTICES.md` | Cómo manejar en React/JS |
| `VERIFICACION_POST_IMPLEMENTACION.md` | Checklist completo |
| `EJEMPLOS_CURL_TESTING.md` | Ejemplos curl listos para copiar |
| `FIX_AUTH_RESUMEN_EJECUTIVO.md` | Resumen ejecutivo |

---

## 🔧 Archivo: Authenticate.php

```php
<?php
namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        // APIs: retornar null (no redirige)
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }
        // Web: redirige a login normalmente
        return route('login');
    }
}
```

---

## 🔧 Cambios en bootstrap/app.php

**Agregar import:**
```php
use App\Http\Middleware\Authenticate;
```

**En `withMiddleware()` agregar:**
```php
$middleware->replace(
    \Illuminate\Auth\Middleware\Authenticate::class,
    Authenticate::class
);
```

---

## 📊 Respuestas

```json
// 401 - No autenticado
{"success": false, "message": "Unauthenticated", "error": "authentication_required"}

// 403 - Sin permisos
{"success": false, "message": "Forbidden", "error": "authorization_failed"}
```

---

## ⚠️ Si Algo No Funciona

| Síntoma | Solución |
|---------|----------|
| "Route [login] not defined" | Verificar que `$middleware->replace()` está en bootstrap/app.php |
| Devuelve HTML | Verificar que request tiene header `Accept: application/json` |
| 500 error | Revisar `storage/logs/laravel.log` |

---

## 🚀 Deployment

```bash
# 1. Commit
git add app/Http/Middleware/Authenticate.php bootstrap/app.php
git commit -m "fix: return JSON 401 on unauthenticated API requests"

# 2. Push
git push

# 3. Deploy (no requiere pasos adicionales)

# 4. Test
curl -H "Accept: application/json" https://produccion.api/api/admin/profesores/6/socios
# Debe devolver JSON 401
```

---

## 📞 Preguntas Frecuentes

**¿Qué rutas están afectadas?**  
Todas las rutas en `/api/` que usen `middleware('auth:sanctum')`

**¿Se rompe algo?**  
No. Solo cambia cómo se maneja autenticación faltante en APIs. Rutas web siguen igual.

**¿Necesito recompilar?**  
No. Solo es PHP/configuración.

**¿Afecta base de datos?**  
No. No hay cambios en BD.

**¿Es seguro en producción?**  
Sí. Solo devuelve JSON en lugar de redirigir. Autenticación sigue siendo igual de segura.

**¿Qué pasa con el frontend?**  
Debe recibir JSON 401/403 y manejar errors adecuadamente (no reintentar infinitamente).

---

## ✨ Summary

✅ Problema resuelto: API devuelve JSON en lugar de error de route  
✅ Rutas protegidas: Siguen requiriendo token y permisos  
✅ Seguridad: Mantiene Sanctum y validación de roles  
✅ Deploy: Sin downtime, no requiere pasos especiales  

**Listo para producción** ✨

