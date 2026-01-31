# Fix: Eliminar redirección a /login en requests no autenticados

## ✅ Cambios Implementados

### 1. **Crear Middleware Personalizado: `Authenticate.php`**
   - **Archivo:** `app/Http/Middleware/Authenticate.php`
   - **Problema:** El middleware de autenticación de Laravel intentaba redirigir a `route('login')` incluso en API requests, causando error: `Route [login] not defined.`
   - **Solución:** Reemplazar el middleware por defecto con uno personalizado que:
     - Si `$request->expectsJson()` o `$request->is('api/*')` → retorna `null` (no redirige)
     - Si es una ruta web normal → retorna `route('login')` (comportamiento estándar)
   - El retorno `null` dispara `AuthenticationException` que es capturada por el exception handler

### 2. **Registrar Middleware en `bootstrap/app.php`**
   - Importar el nuevo `Authenticate` middleware
   - Reemplazar el middleware de autenticación por defecto:
     ```php
     $middleware->replace(
         \Illuminate\Auth\Middleware\Authenticate::class,
         Authenticate::class
     );
     ```

### 3. **Mejorar Exception Handler en `bootstrap/app.php`**
   - Las excepciones de autenticación (`AuthenticationException`) retornan JSON 401:
     ```json
     {
       "success": false,
       "message": "Unauthenticated",
       "error": "authentication_required"
     }
     ```
   - Las excepciones de autorización (`AuthorizationException`) retornan JSON 403:
     ```json
     {
       "success": false,
       "message": "Forbidden",
       "error": "authorization_failed"
     }
     ```

### 4. **Verificar Rutas Protegidas**
   - La ruta `/api/admin/profesores/{id}/socios` está correctamente protegida:
     ```php
     Route::middleware('auth:sanctum')->group(function () {
         Route::prefix('admin')->middleware('admin')->group(function () {
             Route::get('profesores/{profesor}/socios', [ProfesorSocioController::class, 'sociosPorProfesor']);
             Route::post('profesores/{profesor}/socios', [ProfesorSocioController::class, 'syncSocios']);
         });
     });
     ```
   - Guard correcto: `auth:sanctum` (no `auth` que es web)
   - Middleware de autorización: `admin` (verifica rol usando `EnsureAdmin`)

---

## 🧪 Testing con cURL

### Caso 1: SIN autenticación → 401 JSON
```bash
curl -i \
  -H "Accept: application/json" \
  "https://vm-gym-api.test/api/admin/profesores/6/socios?per_page=1000"
```

**Respuesta esperada:**
```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "success": false,
  "message": "Unauthenticated",
  "error": "authentication_required"
}
```

---

### Caso 2: CON token válido pero SIN permisos admin → 403 JSON
```bash
# Primero obtener token de usuario no-admin
TOKEN=$(curl -s -X POST "https://vm-gym-api.test/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "estudiante@example.com",
    "password": "password"
  }' | jq -r '.data.token')

# Luego intentar acceder a endpoint admin
curl -i \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "https://vm-gym-api.test/api/admin/profesores/6/socios?per_page=1000"
```

**Respuesta esperada:**
```http
HTTP/1.1 403 Forbidden
Content-Type: application/json

{
  "success": false,
  "message": "Forbidden",
  "error": "authorization_failed"
}
```

---

### Caso 3: CON token válido Y permisos admin → 200 + datos
```bash
# Primero obtener token de usuario admin
TOKEN=$(curl -s -X POST "https://vm-gym-api.test/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "password"
  }' | jq -r '.data.token')

# Luego acceder a endpoint admin
curl -i \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "https://vm-gym-api.test/api/admin/profesores/6/socios?per_page=1000"
```

**Respuesta esperada:**
```http
HTTP/1.1 200 OK
Content-Type: application/json

{
  "ok": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 123,
        "dni": "12345678",
        "nombre": "Juan",
        "apellido": "Pérez",
        "email": "juan@example.com",
        "user_type": "api",
        ...
      }
    ],
    "from": 1,
    "to": 1,
    "total": 1,
    "per_page": 1000,
    ...
  }
}
```

---

## 🔐 Headers Recomendados

Siempre incluye estos headers en requests API:
```bash
-H "Accept: application/json"              # Indica que espera respuesta JSON
-H "Authorization: Bearer <TOKEN>"         # Token Sanctum/JWT
-H "Content-Type: application/json"        # (si envías body)
```

---

## 📋 Respuestas Estándar de Error

| Código | Escenario | Respuesta |
|--------|-----------|----------|
| **401** | No autenticado | `{"success": false, "message": "Unauthenticated", "error": "authentication_required"}` |
| **403** | Autenticado pero sin permisos | `{"success": false, "message": "Forbidden", "error": "authorization_failed"}` |
| **422** | Error de validación | `{"success": false, "message": "Error de validación", "errors": {...}}` |
| **404** | Endpoint no existe | `{"success": false, "message": "Endpoint no encontrado"}` |
| **405** | Método no permitido | `{"success": false, "message": "Método no permitido"}` |
| **500** | Error del servidor | `{"success": false, "message": "Error interno del servidor"}` |

---

## 🚀 Checklist Postman/Testing

- [ ] Login como admin → obtener token válido
- [ ] GET `/api/admin/profesores/6/socios` SIN header Authorization → 401 JSON ✅
- [ ] GET `/api/admin/profesores/6/socios` CON token estudiante → 403 JSON ✅
- [ ] GET `/api/admin/profesores/6/socios` CON token admin → 200 + datos ✅
- [ ] Verificar que NO hay redirección a `/login` (status 301/302)
- [ ] Verificar que todas las respuestas tienen `Content-Type: application/json`

---

## 📌 Notas Importantes

1. **No hay más redirecciones a `/login`**: El middleware personalizado retorna `null` para APIs, disparando una excepción que devuelve JSON 401
2. **EnsureAdmin verifica permisos**: Si el usuario no tiene rol admin, devuelve 403 JSON automáticamente
3. **El exception handler captura todo**: Todas las excepciones de autenticación/autorización son convertidas a JSON
4. **Frontend debe enviar `Accept: application/json`**: Esto ayuda a identificar que es una request API
5. **No reintentar infinitamente**: Si recibes 401/403, no llamar nuevamente sin cambiar credenciales

---

## 🔧 Archivos Modificados

| Archivo | Cambio | Razón |
|---------|--------|-------|
| `app/Http/Middleware/Authenticate.php` | CREADO | Reemplazar middleware por defecto |
| `bootstrap/app.php` | ACTUALIZADO | Registrar nuevo middleware + mejorar exception handler |
| `routes/api.php` | ✅ OK | Ya está correctamente configurado |

