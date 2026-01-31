# 🎯 Ejemplos Curl Listos para Usar

## 📝 Configuración Previa

```bash
# Variables de entorno (reemplaza con tus valores)
API_URL="https://vm-gym-api.test"  # O tu URL real
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="password"
PROFESOR_ID="6"
PER_PAGE="1000"
```

---

## 1️⃣ Test: SIN Autenticación → 401

```bash
curl -i -H "Accept: application/json" \
  "$API_URL/api/admin/profesores/$PROFESOR_ID/socios?per_page=$PER_PAGE"
```

**Resultado esperado:**
```
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "success": false,
  "message": "Unauthenticated",
  "error": "authentication_required"
}
```

---

## 2️⃣ Obtener Token (Login)

### Opción A: Login simple
```bash
# Guardar token en variable
TOKEN=$(curl -s -X POST "$API_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{
    \"email\": \"$ADMIN_EMAIL\",
    \"password\": \"$ADMIN_PASSWORD\"
  }" | jq -r '.data.token')

# Verificar que se obtuvo el token
echo "Token obtenido: ${TOKEN:0:20}..."
```

### Opción B: Ver la respuesta completa del login
```bash
curl -i -X POST "$API_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{
    \"email\": \"$ADMIN_EMAIL\",
    \"password\": \"$ADMIN_PASSWORD\"
  }"
```

---

## 3️⃣ Test: CON Autenticación y Permisos → 200

```bash
# Obtener el token primero
TOKEN=$(curl -s -X POST "$API_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"$ADMIN_EMAIL\",
    \"password\": \"$ADMIN_PASSWORD\"
  }" | jq -r '.data.token')

# Luego llamar al endpoint con el token
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "$API_URL/api/admin/profesores/$PROFESOR_ID/socios?per_page=$PER_PAGE"
```

**Resultado esperado:**
```
HTTP/1.1 200 OK
Content-Type: application/json

{
  "ok": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "dni": "12345678",
        "nombre": "Juan",
        "apellido": "Pérez",
        "email": "juan@example.com",
        "user_type": "api"
      },
      ...
    ],
    "from": 1,
    "last_page": 1,
    "per_page": 1000,
    "to": 100,
    "total": 100
  }
}
```

---

## 4️⃣ Test: CON Token pero SIN Permisos → 403

```bash
# Obtener token de un usuario NO admin (estudiante)
TOKEN=$(curl -s -X POST "$API_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"estudiante@example.com\",
    \"password\": \"password\"
  }" | jq -r '.data.token')

# Intentar acceder a endpoint admin
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "$API_URL/api/admin/profesores/$PROFESOR_ID/socios"
```

**Resultado esperado:**
```
HTTP/1.1 403 Forbidden
Content-Type: application/json

{
  "success": false,
  "message": "Forbidden",
  "error": "authorization_failed"
}
```

---

## 5️⃣ Test: Token Inválido → 401

```bash
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer invalid_token_12345" \
     "$API_URL/api/admin/profesores/$PROFESOR_ID/socios"
```

**Resultado esperado:**
```
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "success": false,
  "message": "Unauthenticated",
  "error": "authentication_required"
}
```

---

## 6️⃣ Test: Con Búsqueda

```bash
TOKEN=$(curl -s -X POST "$API_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"$ADMIN_EMAIL\",
    \"password\": \"$ADMIN_PASSWORD\"
  }" | jq -r '.data.token')

# Buscar socios con dni que contenga "123"
curl -i -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "$API_URL/api/admin/profesores/$PROFESOR_ID/socios?search=123&per_page=50"
```

---

## 7️⃣ Script Automatizado de Testing

```bash
#!/bin/bash

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

API_URL="https://vm-gym-api.test"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="password"
PROFESOR_ID="6"

echo -e "${YELLOW}=== Test API Autenticación ===${NC}\n"

# Test 1: Sin token
echo -e "${YELLOW}Test 1: Sin autenticación (debe ser 401)${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -H "Accept: application/json" \
  "$API_URL/api/admin/profesores/$PROFESOR_ID/socios")
STATUS=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | head -n -1)
if [ "$STATUS" = "401" ]; then
  echo -e "${GREEN}✓ PASSED${NC}"
else
  echo -e "${RED}✗ FAILED (Status: $STATUS)${NC}"
fi
echo "Respuesta: $BODY\n"

# Test 2: Obtener token
echo -e "${YELLOW}Test 2: Login (debe ser 200)${NC}"
LOGIN_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$API_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"$ADMIN_EMAIL\",
    \"password\": \"$ADMIN_PASSWORD\"
  }")
LOGIN_STATUS=$(echo "$LOGIN_RESPONSE" | tail -n 1)
LOGIN_BODY=$(echo "$LOGIN_RESPONSE" | head -n -1)
TOKEN=$(echo "$LOGIN_BODY" | jq -r '.data.token')

if [ "$LOGIN_STATUS" = "200" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${GREEN}✓ PASSED${NC}"
  echo "Token: ${TOKEN:0:20}..."
else
  echo -e "${RED}✗ FAILED (Status: $LOGIN_STATUS)${NC}"
  exit 1
fi
echo ""

# Test 3: Con token válido
echo -e "${YELLOW}Test 3: Con token admin (debe ser 200)${NC}"
ADMIN_RESPONSE=$(curl -s -w "\n%{http_code}" -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "$API_URL/api/admin/profesores/$PROFESOR_ID/socios?per_page=5")
ADMIN_STATUS=$(echo "$ADMIN_RESPONSE" | tail -n 1)
ADMIN_BODY=$(echo "$ADMIN_RESPONSE" | head -n -1)

if [ "$ADMIN_STATUS" = "200" ]; then
  echo -e "${GREEN}✓ PASSED${NC}"
  TOTAL=$(echo "$ADMIN_BODY" | jq '.data.total')
  echo "Total de socios: $TOTAL"
else
  echo -e "${RED}✗ FAILED (Status: $ADMIN_STATUS)${NC}"
fi
echo ""

echo -e "${GREEN}=== Tests Completados ===${NC}"
```

**Usar:**
```bash
chmod +x test_api.sh
./test_api.sh
```

---

## 8️⃣ Postman Collection

```json
{
  "info": {
    "name": "VM Gym API - Auth Tests",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "1. Test - Sin Token (401)",
      "request": {
        "method": "GET",
        "header": [
          {"key": "Accept", "value": "application/json"}
        ],
        "url": {
          "raw": "{{api_url}}/api/admin/profesores/6/socios?per_page=1000",
          "host": ["{{api_url}}"],
          "path": ["api", "admin", "profesores", "6", "socios"],
          "query": [{"key": "per_page", "value": "1000"}]
        }
      }
    },
    {
      "name": "2. Login - Obtener Token",
      "event": [
        {
          "listen": "test",
          "script": {
            "exec": [
              "var jsonData = pm.response.json();",
              "pm.environment.set('token', jsonData.data.token);"
            ]
          }
        }
      ],
      "request": {
        "method": "POST",
        "header": [
          {"key": "Content-Type", "value": "application/json"}
        ],
        "body": {
          "mode": "raw",
          "raw": "{\\n  \\\"email\\\": \\\"admin@example.com\\\",\\n  \\\"password\\\": \\\"password\\\"\\n}"
        },
        "url": {
          "raw": "{{api_url}}/api/auth/login",
          "host": ["{{api_url}}"],
          "path": ["api", "auth", "login"]
        }
      }
    },
    {
      "name": "3. Socios - Con Token (200)",
      "request": {
        "method": "GET",
        "header": [
          {"key": "Accept", "value": "application/json"},
          {"key": "Authorization", "value": "Bearer {{token}}"}
        ],
        "url": {
          "raw": "{{api_url}}/api/admin/profesores/6/socios?per_page=1000",
          "host": ["{{api_url}}"],
          "path": ["api", "admin", "profesores", "6", "socios"],
          "query": [{"key": "per_page", "value": "1000"}]
        }
      }
    }
  ],
  "variable": [
    {"key": "api_url", "value": "https://vm-gym-api.test"}
  ]
}
```

---

## 9️⃣ Validación Rápida (One-Liner)

```bash
# ¿El API devuelve JSON 401 sin token?
curl -s -H "Accept: application/json" https://vm-gym-api.test/api/admin/profesores/6/socios | jq '.message'

# ¿Se puede loguear?
curl -s -X POST https://vm-gym-api.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' | jq '.data.token'

# ¿Funciona con token?
TOKEN=$(curl -s -X POST https://vm-gym-api.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' | jq -r '.data.token') && \
curl -s -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
  https://vm-gym-api.test/api/admin/profesores/6/socios | jq '.data | length'
```

---

## 🔍 Debugging

```bash
# Ver headers completos
curl -v -H "Accept: application/json" \
  https://vm-gym-api.test/api/admin/profesores/6/socios 2>&1 | grep -A 20 "^<"

# Ver request y response
curl -v -X GET -H "Accept: application/json" \
  https://vm-gym-api.test/api/admin/profesores/6/socios 2>&1 | grep -E "^>|^<"

# Guardar response en archivo
curl -H "Accept: application/json" \
  https://vm-gym-api.test/api/admin/profesores/6/socios > response.json

# Pretty print JSON
curl -s -H "Accept: application/json" \
  https://vm-gym-api.test/api/admin/profesores/6/socios | jq '.'

# Solo ver headers
curl -i -H "Accept: application/json" \
  https://vm-gym-api.test/api/admin/profesores/6/socios | head -20
```

---

**Nota:** Reemplaza:
- `https://vm-gym-api.test` con tu URL real
- `admin@example.com` con email de admin existente
- `password` con contraseña correcta
- `6` con ID de profesor que existe

