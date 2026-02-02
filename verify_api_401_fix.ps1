# ============================================================
# Script de verificación: API 401 sin redirect a login (PowerShell)
# ============================================================

Write-Host "🔍 Verificando configuración de autenticación API..." -ForegroundColor Cyan
Write-Host ""

# Flag para resumen
$allPass = $true

# ============================================================
# 1. Verificar que Authenticate.php retorna null para API
# ============================================================
Write-Host "📋 Verificación 1: Middleware Authenticate.php" -ForegroundColor Yellow
$authMiddleware = Get-Content "app\Http\Middleware\Authenticate.php" -Raw

if ($authMiddleware -match "if \(\`$request->expectsJson\(\) \|\| \`$request->is\('api/\*'\)\)") {
    Write-Host "✅ Encontrado: Check para API en redirectTo()" -ForegroundColor Green
} else {
    Write-Host "❌ NO encontrado: Check para API en redirectTo()" -ForegroundColor Red
    $allPass = $false
}

if ($authMiddleware -match "return null;") {
    Write-Host "✅ Encontrado: return null para rutas API" -ForegroundColor Green
} else {
    Write-Host "❌ NO encontrado: return null para rutas API" -ForegroundColor Red
    $allPass = $false
}

Write-Host ""

# ============================================================
# 2. Verificar que Exception Handler captura AuthenticationException
# ============================================================
Write-Host "📋 Verificación 2: Exception Handler en bootstrap/app.php" -ForegroundColor Yellow
$bootstrapApp = Get-Content "bootstrap\app.php" -Raw

if ($bootstrapApp -match "AuthenticationException") {
    Write-Host "✅ Encontrado: Manejo de AuthenticationException" -ForegroundColor Green
} else {
    Write-Host "❌ NO encontrado: Manejo de AuthenticationException" -ForegroundColor Red
    $allPass = $false
}

if ($bootstrapApp -match "Unauthenticated") {
    Write-Host "✅ Encontrado: Mensaje 'Unauthenticated' en respuesta" -ForegroundColor Green
} else {
    Write-Host "❌ NO encontrado: Mensaje 'Unauthenticated'" -ForegroundColor Red
    $allPass = $false
}

if ($bootstrapApp -match ", 401") {
    Write-Host "✅ Encontrado: Status code 401 para autenticación" -ForegroundColor Green
} else {
    Write-Host "❌ NO encontrado: Status code 401" -ForegroundColor Red
    $allPass = $false
}

Write-Host ""

# ============================================================
# 3. Verificar que el archivo contiene "success" en respuestas JSON
# ============================================================
Write-Host "📋 Verificación 3: Respuestas JSON bien formadas" -ForegroundColor Yellow

if ($bootstrapApp -match "'success' => false") {
    Write-Host "✅ Encontrado: Campo 'success' => false en respuestas" -ForegroundColor Green
} else {
    Write-Host "❌ NO encontrado: Campo 'success' en respuestas" -ForegroundColor Red
    $allPass = $false
}

Write-Host ""

# ============================================================
# 4. Verificar configuración de Sanctum
# ============================================================
Write-Host "📋 Verificación 4: Configuración de Sanctum" -ForegroundColor Yellow
if (Test-Path "config\sanctum.php") {
    Write-Host "✅ Encontrado: config/sanctum.php" -ForegroundColor Green
} else {
    Write-Host "⚠️  NO encontrado: config/sanctum.php" -ForegroundColor Yellow
}

Write-Host ""

# ============================================================
# 5. Sugerir comandos de limpieza
# ============================================================
Write-Host "🧹 Comandos de limpieza recomendados:" -ForegroundColor Cyan
Write-Host ""
Write-Host "   php artisan route:clear" -ForegroundColor Gray
Write-Host "   php artisan config:clear" -ForegroundColor Gray
Write-Host "   php artisan cache:clear" -ForegroundColor Gray
Write-Host "   php artisan view:clear" -ForegroundColor Gray
Write-Host ""

# ============================================================
# 6. Resumen final
# ============================================================
Write-Host "📊 RESUMEN" -ForegroundColor Magenta
Write-Host "===========================================" -ForegroundColor Magenta

if ($allPass) {
    Write-Host "✅ Todas las verificaciones pasaron" -ForegroundColor Green
    Write-Host ""
    Write-Host "Próximos pasos:" -ForegroundColor Cyan
    Write-Host "1. Ejecutar: php artisan route:clear" -ForegroundColor Gray
    Write-Host "2. Ejecutar: php artisan config:clear" -ForegroundColor Gray
    Write-Host "3. Ejecutar: php artisan cache:clear" -ForegroundColor Gray
    Write-Host "4. Probar con curl (ver FIX_API_401_SIN_REDIRECT.md)" -ForegroundColor Gray
    exit 0
} else {
    Write-Host "❌ Algunas verificaciones fallaron" -ForegroundColor Red
    Write-Host ""
    Write-Host "Verifica los cambios en:" -ForegroundColor Yellow
    Write-Host "- app\Http\Middleware\Authenticate.php" -ForegroundColor Gray
    Write-Host "- bootstrap\app.php" -ForegroundColor Gray
    exit 1
}
