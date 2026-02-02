#!/bin/bash

# ============================================================
# Script de verificación: API 401 sin redirect a login
# ============================================================

echo "🔍 Verificando configuración de autenticación API..."
echo ""

# Colores
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Flag para resumen
ALL_PASS=true

# ============================================================
# 1. Verificar que Authenticate.php retorna null para API
# ============================================================
echo "📋 Verificación 1: Middleware Authenticate.php"
if grep -q "if (\$request->expectsJson() || \$request->is('api/\*'))" app/Http/Middleware/Authenticate.php; then
    echo -e "${GREEN}✅ Encontrado: Check para API en redirectTo()${NC}"
else
    echo -e "${RED}❌ NO encontrado: Check para API en redirectTo()${NC}"
    ALL_PASS=false
fi

if grep -q "return null;" app/Http/Middleware/Authenticate.php; then
    echo -e "${GREEN}✅ Encontrado: return null para rutas API${NC}"
else
    echo -e "${RED}❌ NO encontrado: return null para rutas API${NC}"
    ALL_PASS=false
fi

echo ""

# ============================================================
# 2. Verificar que Exception Handler captura AuthenticationException
# ============================================================
echo "📋 Verificación 2: Exception Handler en bootstrap/app.php"
if grep -q "AuthenticationException" bootstrap/app.php; then
    echo -e "${GREEN}✅ Encontrado: Manejo de AuthenticationException${NC}"
else
    echo -e "${RED}❌ NO encontrado: Manejo de AuthenticationException${NC}"
    ALL_PASS=false
fi

if grep -q "Unauthenticated" bootstrap/app.php; then
    echo -e "${GREEN}✅ Encontrado: Mensaje 'Unauthenticated' en respuesta${NC}"
else
    echo -e "${RED}❌ NO encontrado: Mensaje 'Unauthenticated'${NC}"
    ALL_PASS=false
fi

if grep -q ", 401" bootstrap/app.php; then
    echo -e "${GREEN}✅ Encontrado: Status code 401 para autenticación${NC}"
else
    echo -e "${RED}❌ NO encontrado: Status code 401${NC}"
    ALL_PASS=false
fi

echo ""

# ============================================================
# 3. Verificar que NO existe ruta 'login' (para confirmar que
#    el fix no causará error al intentar obtenerla)
# ============================================================
echo "📋 Verificación 3: Rutas del proyecto"
if php artisan route:list 2>/dev/null | grep -q "login"; then
    echo -e "${YELLOW}⚠️  Advertencia: Existe ruta 'login' definida${NC}"
else
    echo -e "${GREEN}✅ OK: No existe ruta 'login' (esperado, API pura)${NC}"
fi

echo ""

# ============================================================
# 4. Verificar configuración de Sanctum
# ============================================================
echo "📋 Verificación 4: Configuración de Sanctum"
if [ -f "config/sanctum.php" ]; then
    echo -e "${GREEN}✅ Encontrado: config/sanctum.php${NC}"
else
    echo -e "${RED}❌ NO encontrado: config/sanctum.php${NC}"
    ALL_PASS=false
fi

echo ""

# ============================================================
# 5. Sugerir comandos de limpieza
# ============================================================
echo "🧹 Comandos de limpieza recomendados:"
echo ""
echo "   php artisan route:clear"
echo "   php artisan config:clear"
echo "   php artisan cache:clear"
echo "   php artisan view:clear"
echo ""

# ============================================================
# 6. Resumen final
# ============================================================
echo "📊 RESUMEN"
echo "=========================================="
if [ "$ALL_PASS" = true ]; then
    echo -e "${GREEN}✅ Todas las verificaciones pasaron${NC}"
    echo ""
    echo "Próximos pasos:"
    echo "1. Ejecutar: php artisan route:clear"
    echo "2. Ejecutar: php artisan config:clear"
    echo "3. Ejecutar: php artisan cache:clear"
    echo "4. Probar con curl (ver FIX_API_401_SIN_REDIRECT.md)"
    exit 0
else
    echo -e "${RED}❌ Algunas verificaciones fallaron${NC}"
    echo ""
    echo "Verifica los cambios en:"
    echo "- app/Http/Middleware/Authenticate.php"
    echo "- bootstrap/app.php"
    exit 1
fi
