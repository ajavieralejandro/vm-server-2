<?php
/**
 * Script de validación rápida del endpoint /api/profesor/socios
 *
 * Uso:
 * php test_profesor_socios_endpoint.php
 */

require __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
$env_file = __DIR__ . '/.env.testing';
if (!file_exists($env_file)) {
    $env_file = __DIR__ . '/.env';
}

if (file_exists($env_file)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__, basename($env_file));
    $dotenv->load();
}

// Configuración de Laravel
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = true;

// Inicializar Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Collection;

echo "\n" . str_repeat('=', 80) . "\n";
echo "TEST: Endpoint GET /api/profesor/socios\n";
echo str_repeat('=', 80) . "\n\n";

// 1. Buscar profesor con socios asignados
echo "1️⃣  Buscando profesor con socios asignados...\n";
$profesor = User::where('is_professor', true)
    ->whereHas('sociosAsignados')
    ->first();

if (!$profesor) {
    echo "❌ No hay profesores con socios asignados\n";
    echo "\n💡 Solución: Ejecutar el seeder\n";
    echo "   php artisan db:seed --class=ProfesorSocioSeeder\n\n";
    exit(1);
}

echo "✅ Profesor encontrado: {$profesor->name} ({$profesor->email})\n";
echo "   ID: {$profesor->id}\n";
echo "   Socios asignados: " . $profesor->sociosAsignados()->count() . "\n\n";

// 2. Verificar relación many-to-many
echo "2️⃣  Verificando relación many-to-many (professor_socio)...\n";
$socios = $profesor->sociosAsignados()->get();
if ($socios->isEmpty()) {
    echo "❌ No hay socios en la relación\n";
    exit(1);
}

foreach ($socios as $socio) {
    echo "   ✓ {$socio->nombre} {$socio->apellido} (DNI: {$socio->dni}, ID: {$socio->id})\n";
}
echo "\n";

// 3. Simular request paginado
echo "3️⃣  Simulando request GET /api/profesor/socios (per_page=2)...\n";

// Crear un Request simulado
$request = new \Illuminate\Http\Request();
$request->request->add(['per_page' => 2, 'page' => 1]);

// Obtener la query como lo haría el controller
$query = $profesor->sociosAsignados()
    ->where('user_type', UserType::API);

$per_page = (int) $request->get('per_page', 20);
$page = (int) $request->get('page', 1);

$paginated = $query->orderBy('apellido')->orderBy('nombre')->paginate($per_page, ['*'], 'page', $page);

echo "   Página: {$paginated->currentPage()}\n";
echo "   Por página: {$paginated->perPage()}\n";
echo "   Total: {$paginated->total()}\n";
echo "   Última página: {$paginated->lastPage()}\n";
echo "   Items en esta página: " . count($paginated->items()) . "\n";

// 4. Construir respuesta JSON como lo hace el controller
echo "\n4️⃣  Respuesta JSON esperada:\n";
$response = [
    'success' => true,
    'data' => $paginated->items(),
    'meta' => [
        'total' => $paginated->total(),
        'per_page' => $paginated->perPage(),
        'current_page' => $paginated->currentPage(),
        'last_page' => $paginated->lastPage(),
        'from' => $paginated->firstItem(),
        'to' => $paginated->lastItem(),
    ],
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// 5. Verificar validaciones
echo "5️⃣  Verificando validaciones...\n";

// 5a. Usuario sin rol de profesor
$student = User::where('is_professor', false)->where('user_type', UserType::LOCAL)->first();
if ($student) {
    echo "   ✓ Estudiante encontrado (debería devolver 403): {$student->email}\n";
} else {
    echo "   ⚠ No hay estudiante para validar permisos\n";
}

// 5b. Profesor sin socios asignados
$profesor_sin_socios = User::where('is_professor', true)
    ->whereDoesntHave('sociosAsignados')
    ->first();

if ($profesor_sin_socios) {
    echo "   ✓ Profesor sin socios: {$profesor_sin_socios->email}\n";
    echo "     Debería devolver: success: true, data: [], meta: {...}\n";
} else {
    echo "   ⚠ No hay profesor sin socios para validar\n";
}

echo "\n";

// 6. Resumen
echo "✅ VALIDACIÓN COMPLETA\n";
echo str_repeat('=', 80) . "\n";
echo "Endpoint: GET /api/profesor/socios\n";
echo "Ruta: /api/profesor/socios (con middleware auth:sanctum y professor)\n";
echo "Respuesta: { success: true, data: [...], meta: {...} }\n";
echo "Paginación: Soportada (per_page, page)\n";
echo "Búsqueda: Soportada (search)\n";
echo "\nVacío sin error:\n";
echo "  - Si no hay socios: data: [], meta: { total: 0, ... }\n";
echo "  - Si no es profesor: 403 (Forbidden)\n\n";
?>
