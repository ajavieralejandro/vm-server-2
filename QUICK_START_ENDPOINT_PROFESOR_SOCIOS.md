# 🚀 QUICK START - Endpoint /api/profesor/socios

## Iniciación Rápida (5 minutos)

### Paso 1: Ejecutar migraciones
```bash
php artisan migrate
```

### Paso 2: Cargar datos de prueba
```bash
php artisan db:seed --class=ProfesorSocioSeeder
```

Esto crea automáticamente:
- **Profesor**: `profesor_socios@test.com` (password: `profesor123`)
- **5 Socios**: `socios.test_001@test.com` - `socios.test_005@test.com` (password: `socio123`)

### Paso 3: Validar instalación (opcional)
```bash
php test_profesor_socios_endpoint.php
```

---

## 🧪 Testing con cURL

### 1️⃣ Login
```bash
TOKEN=$(curl -s -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "profesor_socios@test.com",
    "password": "profesor123"
  }' | jq -r '.data.token')

echo $TOKEN  # Guardar para próximos requests
```

### 2️⃣ Listar socios asignados
```bash
curl -X GET "http://localhost:8000/api/profesor/socios" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq
```

**Respuesta esperada**:
```json
{
  "success": true,
  "data": [
    {
      "id": 4,
      "dni": "40000001",
      "nombre": "Juan",
      "apellido": "Pérez",
      "user_type": "api"
    },
    ...
  ],
  "meta": {
    "total": 5,
    "per_page": 20,
    "current_page": 1,
    "last_page": 1
  }
}
```

### 3️⃣ Paginación
```bash
curl -X GET "http://localhost:8000/api/profesor/socios?per_page=2&page=1" \
  -H "Authorization: Bearer $TOKEN" | jq
```

### 4️⃣ Buscar por DNI
```bash
curl -X GET "http://localhost:8000/api/profesor/socios?search=40000001" \
  -H "Authorization: Bearer $TOKEN" | jq
```

### 5️⃣ Socios disponibles (no asignados)
```bash
curl -X GET "http://localhost:8000/api/profesor/socios/disponibles" \
  -H "Authorization: Bearer $TOKEN" | jq
```

### 6️⃣ Asignar nuevo socio
```bash
# Primero obtener ID de socio disponible
SOCIO_ID=6  # Ejemplo

curl -X POST "http://localhost:8000/api/profesor/socios/$SOCIO_ID" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq
```

**Respuesta esperada**:
```json
{
  "success": true,
  "message": "Socio asignado correctamente",
  "data": {
    "profesor_id": 1,
    "socio_id": 6,
    "socio": {
      "id": 6,
      "dni": "40000006",
      "nombre": "Ana",
      "apellido": "González"
    }
  }
}
```

### 7️⃣ Desasignar socio
```bash
curl -X DELETE "http://localhost:8000/api/profesor/socios/$SOCIO_ID" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq
```

---

## 🔍 Validaciones

### ✅ Si eres profesor y tienes socios
```
GET /api/profesor/socios → 200 OK con data
```

### ✅ Si eres profesor pero no tienes socios
```
GET /api/profesor/socios → 200 OK con data: []
```

### ✅ Si eres estudiante (sin rol profesor)
```
GET /api/profesor/socios → 403 Forbidden
"No autorizado: solo profesores pueden acceder a esta ruta"
```

### ✅ Si intentas asignar usuario que no es API
```
POST /api/profesor/socios/1 → 422 Unprocessable Entity
"El usuario debe ser un socio (tipo API)"
```

### ✅ Si intentas asignar socio ya asignado
```
POST /api/profesor/socios/2 → 422 Unprocessable Entity
"El socio ya está asignado a este profesor"
```

### ✅ Si intentas desasignar socio que no está asignado
```
DELETE /api/profesor/socios/99 → 404 Not Found
"El socio no está asignado a este profesor"
```

---

## 📊 Estructura de Respuesta

### GET /api/profesor/socios
```json
{
  "success": true,
  "data": [
    {
      "id": 4,
      "name": "Pérez, Juan",
      "email": "juan@test.com",
      "dni": "40000001",
      "nombre": "Juan",
      "apellido": "Pérez",
      "user_type": "api",
      "socio_id": "API_40000001",
      "created_at": "2026-02-02T10:00:00Z",
      "updated_at": "2026-02-02T10:00:00Z",
      "pivot": {
        "professor_id": 1,
        "socio_id": 4,
        "assigned_by": 1,
        "created_at": "2026-02-02T10:00:00Z",
        "updated_at": "2026-02-02T10:00:00Z"
      }
    }
  ],
  "meta": {
    "total": 5,
    "per_page": 20,
    "current_page": 1,
    "last_page": 1,
    "from": 1,
    "to": 5
  }
}
```

---

## 🔐 Requisitos Cumplidos

| Requisito | Status | Detalles |
|-----------|--------|----------|
| Ruta en `routes/api.php` | ✅ | `/api/profesor/socios` bajo `auth:sanctum` |
| Profesor autenticado | ✅ | Middleware `auth:sanctum` aplicado |
| Resolver professor_id | ✅ | `auth()->user()->id` automático |
| Tabla pivote | ✅ | `professor_socio` con constraints |
| Paginación | ✅ | `per_page=20` (default), `page=1` (default) |
| Formato JSON | ✅ | `{ success: true, data: [...], meta: {...} }` |
| Vacío sin error | ✅ | `data: []` si no hay socios |
| Tests/Seeders | ✅ | `ProfesorSocioSeeder` incluido |

---

## 📚 Documentación Completa

Ver: [IMPLEMENTACION_ENDPOINT_PROFESOR_SOCIOS.md](./IMPLEMENTACION_ENDPOINT_PROFESOR_SOCIOS.md)

---

## 🆘 Troubleshooting

### Error: "No such table: professor_socio"
```bash
php artisan migrate
```

### Error: "No professors found with socios"
```bash
php artisan db:seed --class=ProfesorSocioSeeder
```

### Error: "No profesores pueden acceder" (403)
Asegúrate que:
- [ ] Estás logueado (token válido)
- [ ] El usuario tiene `is_professor = 1` en BD
- [ ] El token no ha expirado

### Token inválido
Ejecutar login nuevamente:
```bash
curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "profesor_socios@test.com", "password": "profesor123"}'
```

---

**¡Listo para usar! 🎉**
