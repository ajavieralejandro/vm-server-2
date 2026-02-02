#!/bin/bash

# ============================================================
# Ejemplos CURL para verificar fix API 401
# Copiar y pegar en terminal
# ============================================================

BASE_URL="http://localhost:8000"

# Colores
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║          Verificación de API 401 - Tests CURL                 ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# ============================================================
# Test 1: Sin token (DEBE retornar 401)
# ============================================================
echo -e "${YELLOW}🧪 TEST 1: Request SIN token${NC}"
echo -e "${BLUE}Comando:${NC}"
echo "curl -X GET \"$BASE_URL/api/admin/socios\" \\"
echo "  -H \"Accept: application/json\" \\"
echo "  -v"
echo ""

echo -e "${YELLOW}Ejecutando...${NC}"
curl -X GET "$BASE_URL/api/admin/socios" \
  -H "Accept: application/json" \
  -w "\n%{http_code}\n" \
  2>&1 | tail -20

echo ""
echo -e "${GREEN}Esperado: 401 con JSON { \"success\": false, \"message\": \"Unauthenticated.\" }${NC}"
echo ""
echo "════════════════════════════════════════════════════════════════"
echo ""

# ============================================================
# Test 2: Con token inválido (DEBE retornar 401)
# ============================================================
echo -e "${YELLOW}🧪 TEST 2: Request con token INVÁLIDO${NC}"
echo -e "${BLUE}Comando:${NC}"
echo "curl -X GET \"$BASE_URL/api/admin/socios\" \\"
echo "  -H \"Accept: application/json\" \\"
echo "  -H \"Authorization: Bearer invalid_token_12345\" \\"
echo "  -v"
echo ""

echo -e "${YELLOW}Ejecutando...${NC}"
curl -X GET "$BASE_URL/api/admin/socios" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer invalid_token_12345" \
  -w "\n%{http_code}\n" \
  2>&1 | tail -20

echo ""
echo -e "${GREEN}Esperado: 401 con JSON { \"success\": false, \"message\": \"Unauthenticated.\" }${NC}"
echo ""
echo "════════════════════════════════════════════════════════════════"
echo ""

# ============================================================
# Test 3: Login válido (obtener token)
# ============================================================
echo -e "${YELLOW}🧪 TEST 3: Login para obtener token${NC}"
echo -e "${BLUE}Comando:${NC}"
echo "curl -X POST \"$BASE_URL/api/auth/login\" \\"
echo "  -H \"Content-Type: application/json\" \\"
echo "  -d '{\"email\":\"admin@test.com\",\"password\":\"password\"}'"
echo ""

echo -e "${YELLOW}Ejecutando...${NC}"
TOKEN_RESPONSE=$(curl -s -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"password"}')

echo "$TOKEN_RESPONSE" | jq '.' 2>/dev/null || echo "$TOKEN_RESPONSE"

# Intentar extraer token
TOKEN=$(echo "$TOKEN_RESPONSE" | jq -r '.data.token' 2>/dev/null)

echo ""
echo -e "${GREEN}Esperado: 200 con token en .data.token${NC}"
echo ""
echo "════════════════════════════════════════════════════════════════"
echo ""

# ============================================================
# Test 4: Con token válido (DEBE retornar 200 o 403 si no tiene rol)
# ============================================================
if [ "$TOKEN" != "null" ] && [ -n "$TOKEN" ]; then
    echo -e "${YELLOW}🧪 TEST 4: Request con token VÁLIDO${NC}"
    echo -e "${BLUE}Comando:${NC}"
    echo "curl -X GET \"$BASE_URL/api/admin/socios\" \\"
    echo "  -H \"Accept: application/json\" \\"
    echo "  -H \"Authorization: Bearer \$TOKEN\" \\"
    echo "  -v"
    echo ""

    echo -e "${YELLOW}Ejecutando...${NC}"
    curl -X GET "$BASE_URL/api/admin/socios" \
      -H "Accept: application/json" \
      -H "Authorization: Bearer $TOKEN" \
      -w "\n%{http_code}\n" \
      2>&1 | tail -20

    echo ""
    echo -e "${GREEN}Esperado: 200 (si tiene rol admin) o 403 (si no tiene rol)${NC}"
    echo ""
else
    echo -e "${RED}⚠️  No se pudo obtener token, skipeando Test 4${NC}"
    echo "Verifica que exista usuario admin@test.com con password correcta"
    echo ""
fi

echo "════════════════════════════════════════════════════════════════"
echo ""

# ============================================================
# Test 5: Request a endpoint que NO existe (DEBE retornar 404)
# ============================================================
echo -e "${YELLOW}🧪 TEST 5: Request a endpoint NO EXISTENTE${NC}"
echo -e "${BLUE}Comando:${NC}"
echo "curl -X GET \"$BASE_URL/api/endpoint/inexistente\" \\"
echo "  -H \"Accept: application/json\" \\"
echo "  -v"
echo ""

echo -e "${YELLOW}Ejecutando...${NC}"
curl -X GET "$BASE_URL/api/endpoint/inexistente" \
  -H "Accept: application/json" \
  -w "\n%{http_code}\n" \
  2>&1 | tail -20

echo ""
echo -e "${GREEN}Esperado: 404 con JSON { \"success\": false, \"message\": \"Not found\" }${NC}"
echo ""
echo "════════════════════════════════════════════════════════════════"
echo ""

# ============================================================
# Resumen
# ============================================================
echo -e "${BLUE}📊 RESUMEN DE TESTS${NC}"
echo ""
echo -e "${GREEN}✅ Si todos los tests retornaron JSON (no HTML, no redirect):${NC}"
echo "   El fix está funcionando correctamente."
echo ""
echo -e "${RED}❌ Si algún test devolvió HTML o error 500:${NC}"
echo "   Ejecutar comandos de limpieza:"
echo "   $ php artisan route:clear"
echo "   $ php artisan config:clear"
echo "   $ php artisan cache:clear"
echo ""
echo -e "${YELLOW}ℹ️  Status code esperados:${NC}"
echo "   - 401: Sin autenticación"
echo "   - 403: Autenticado pero sin permisos"
echo "   - 404: Endpoint no existe"
echo "   - 200: OK"
echo ""
