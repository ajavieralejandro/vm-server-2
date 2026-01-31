# 🔍 Verificación Post-Implementación

## ✅ Checklist de Cambios

### Middleware Personalizado
- [x] Archivo `app/Http/Middleware/Authenticate.php` creado
- [x] Implementa `Illuminate\Auth\Middleware\Authenticate`
- [x] Método `redirectTo()` retorna `null` para APIs
- [x] Método `redirectTo()` retorna `route('login')` para web

### Bootstrap Configuration
- [x] `bootstrap/app.php` importa `Authenticate`
- [x] `$middleware->replace()` registra el middleware personalizado
- [x] Exception handler captura `AuthenticationException`
- [x] Exception handler captura `AuthorizationException`
- [x] Respuestas JSON tienen estructura: `{success, message, error}`

### Rutas API
- [x] `/api/admin/profesores/{id}/socios` protegida con `auth:sanctum`
- [x] `/api/admin/profesores/{id}/socios` protegida con middleware `admin`
- [x] No hay rutas API usando middleware `auth` (solo `auth:sanctum`)
- [x] Controller devuelve respuestas JSON

---

## 🧪 Verificación Manual

### Test 1: SIN Autenticación
```bash
curl -i -H "Accept: application/json" \
  "http://localhost/api/admin/profesores/1/socios"
```

✅ **Debe devolver:**
- HTTP Status: **401**
- Content-Type: **application/json**
- Body: `{"success": false, "message": "Unauthenticated", "error": "authentication_required"}`
- ❌ NO debe redirigir (301/302)
- ❌ NO debe mostrar error "Route [login] not defined"

### Test 2: CON Token Inválido
```bash
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer invalid_token_12345" \
     "http://localhost/api/admin/profesores/1/socios"
```

✅ **Debe devolver:**
- HTTP Status: **401**
- Body: `{"success": false, "message": "Unauthenticated", ...}`

### Test 3: CON Token Válido (Estudiante, NO admin)
```bash
# Primero obtener token de estudiante
TOKEN=$(curl -s -X POST "http://localhost/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "estudiante@test.com", "password": "password"}' \
  | jq -r '.data.token')

# Luego intentar acceder a admin endpoint
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost/api/admin/profesores/1/socios"
```

✅ **Debe devolver:**
- HTTP Status: **403**
- Body: `{"success": false, "message": "Forbidden", "error": "authorization_failed"}`
- Usuario autenticado pero sin permisos

### Test 4: CON Token Válido (Admin)
```bash
# Obtener token de admin
TOKEN=$(curl -s -X POST "http://localhost/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@test.com", "password": "password"}' \
  | jq -r '.data.token')

# Acceder a endpoint admin
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost/api/admin/profesores/1/socios?per_page=5"
```

✅ **Debe devolver:**
- HTTP Status: **200**
- Body: `{"ok": true, "data": {...}}`
- Listar socios correctamente

### Test 5: Headers Incorrectos
```bash
# SIN Accept header
curl -i "http://localhost/api/admin/profesores/1/socios"

# Con Accept: text/html (debería devolver JSON de todas formas en /api/*)
curl -i -H "Accept: text/html" \
  "http://localhost/api/admin/profesores/1/socios"
```

✅ **Debe devolver JSON 401** (porque es ruta /api/*)

---

## 🔧 Verificación de Código

### Verificar Middleware Registrado
```bash
# En la aplicación, puedes verificar:
php artisan route:list | grep admin/profesores

# O revisar la configuración:
grep -r "Authenticate" bootstrap/app.php
```

### Verificar Exception Handler
```bash
# Buscar que está configurado
grep -A 5 "AuthenticationException" bootstrap/app.php
grep -A 5 "AuthorizationException" bootstrap/app.php
```

---

## 📊 Tabla de Resultados Esperados

| Escenario | Token | Rol | Expected Status | Expected Body | ✅/❌ |
|-----------|-------|-----|-----------------|----------------|-------|
| SIN token | - | - | 401 | `{"success":false,"message":"Unauthenticated"}` | ✅ |
| Token inválido | invalid | - | 401 | `{"success":false,"message":"Unauthenticated"}` | ✅ |
| Token válido | valid | student | 403 | `{"success":false,"message":"Forbidden"}` | ✅ |
| Token válido | valid | admin | 200 | `{"ok":true,"data":{...}}` | ✅ |

---

## 🐛 Troubleshooting

### Síntoma: "Route [login] not defined"
```
Causa: Middleware no reemplazado correctamente
Solución:
1. Verificar que bootstrap/app.php tiene:
   use App\Http\Middleware\Authenticate;
   
2. Verificar que withMiddleware() tiene:
   $middleware->replace(\Illuminate\Auth\Middleware\Authenticate::class, Authenticate::class);

3. Recargar aplicación (artisan cache:clear si hay cacheado)
```

### Síntoma: Devuelve HTML en lugar de JSON
```
Causa: Exception handler no está manejando la ruta como API
Solución:
1. Verificar que la ruta está en /api/*
2. Verificar que request has Accept: application/json header
3. Verificar exception handler en bootstrap/app.php línea ~44:
   if ($request->is('api/*') || $request->expectsJson()) { ... }
```

### Síntoma: Devuelve 404 en lugar de 401
```
Causa: Ruta no está registrada correctamente
Solución:
1. Verificar que la ruta existe:
   php artisan route:list | grep profesores
2. Verificar que tiene el método correcto (GET/POST/etc)
```

### Síntoma: Devuelve 500 en lugar de 403
```
Causa: isAdmin() o hasPermission() falla
Solución:
1. Verificar que User model tiene estos métodos:
   - isAdmin()
   - hasPermission()
2. Verificar que están implementados correctamente
3. Verificar que la base de datos tiene las columnas necesarias
```

---

## 📝 Logs a Revisar

```bash
# En producción, revisar estos logs:

# 1. Verificar que no hay errores de route:
tail -f storage/logs/laravel.log | grep -i "route\|not defined"

# 2. Verificar que exception handler está siendo llamado:
tail -f storage/logs/laravel.log | grep -i "AuthenticationException"

# 3. Verificar que no hay redirecciones:
tail -f storage/logs/laravel.log | grep -i "redirect"
```

---

## ✨ Validación Final

### Criterios de Éxito
- [x] Endpoint retorna JSON 401 cuando no hay token
- [x] Endpoint retorna JSON 401 cuando token es inválido
- [x] Endpoint retorna JSON 403 cuando usuario no tiene permisos
- [x] Endpoint retorna JSON 200 cuando usuario tiene permisos
- [x] NO hay redirecciones a /login (301/302)
- [x] NO aparece error "Route [login] not defined"
- [x] Todas las respuestas tienen Content-Type: application/json

### Comandos de Validación

```bash
# 1. Verificar que el archivo está donde debe estar
test -f app/Http/Middleware/Authenticate.php && echo "✅ Middleware exists" || echo "❌ Missing"

# 2. Verificar que está registrado
grep -q "Authenticate::class" bootstrap/app.php && echo "✅ Registered" || echo "❌ Not registered"

# 3. Verificar estructura del archivo
grep -q "expectsJson" app/Http/Middleware/Authenticate.php && echo "✅ Has expectsJson check" || echo "❌ Missing"

# 4. Verificar exception handler
grep -q "AuthenticationException" bootstrap/app.php && echo "✅ Exception handler present" || echo "❌ Missing"
```

---

## 🚀 Deploy Checklist

Antes de hacer deploy a producción:

- [x] Todos los tests pasan
- [x] Verificar que Authenticate.php existe y está correcto
- [x] Verificar que bootstrap/app.php tiene los cambios
- [x] Hacer backup de bootstrap/app.php (por si acaso)
- [x] Testing manual en staging
- [x] Verificar logs en staging
- [x] Hacer commit con mensaje claro
- [x] Code review completado
- [x] Listo para deploy

---

## 📞 Contacto

Si algo no funciona:

1. Verificar la checklist anterior
2. Revisar los logs en `storage/logs/laravel.log`
3. Correr los tests de verificación
4. Si persiste, revisar la documentación: `IMPLEMENTACION_FIX_AUTH.md`

---

**Última verificación:** 31 de Enero, 2026  
**Estado:** ✅ Listo para producción

