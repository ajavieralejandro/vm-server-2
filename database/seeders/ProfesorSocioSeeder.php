<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProfesorSocioSeeder extends Seeder
{
    /**
     * Seed profesor con socios asignados para testing del endpoint /api/profesor/socios
     *
     * Crea:
     * - 1 Profesor: profesor_socios@test.com (is_professor=true, is_admin=false)
     * - 5 Socios API: socios.test_001@test.com - socios.test_005@test.com (user_type='api')
     * - Asigna los 5 socios al profesor para testing
     */
    public function run(): void
    {
        // 1. Crear un profesor si no existe
        $profesor = User::firstOrCreate(
            ['email' => 'profesor_socios@test.com'],
            [
                'name' => 'Profesor Testing Socios',
                'dni' => '99999998',
                'password' => bcrypt('profesor123'),
                'user_type' => UserType::LOCAL->value,
                'is_admin' => false,
                'is_professor' => true,
                'professor_since' => now(),
                'account_status' => 'active',
            ]
        );

        echo "✓ Profesor creado/encontrado: {$profesor->email} (ID: {$profesor->id})\n";

        // 2. Crear 5 socios de tipo API
        $socios_data = [
            ['dni' => '40000001', 'nombre' => 'Juan', 'apellido' => 'Pérez', 'email' => 'socios.test_001@test.com'],
            ['dni' => '40000002', 'nombre' => 'María', 'apellido' => 'García', 'email' => 'socios.test_002@test.com'],
            ['dni' => '40000003', 'nombre' => 'Carlos', 'apellido' => 'López', 'email' => 'socios.test_003@test.com'],
            ['dni' => '40000004', 'nombre' => 'Ana', 'apellido' => 'Martínez', 'email' => 'socios.test_004@test.com'],
            ['dni' => '40000005', 'nombre' => 'Luis', 'apellido' => 'Rodríguez', 'email' => 'socios.test_005@test.com'],
        ];

        $socios = [];
        foreach ($socios_data as $data) {
            $socio = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => "{$data['apellido']}, {$data['nombre']}",
                    'dni' => $data['dni'],
                    'password' => bcrypt('socio123'),
                    'user_type' => UserType::API->value,
                    'nombre' => $data['nombre'],
                    'apellido' => $data['apellido'],
                    'is_admin' => false,
                    'is_professor' => false,
                    'account_status' => 'active',
                    'socio_id' => "API_{$data['dni']}",  // Simular socio_id del sistema
                ]
            );
            $socios[] = $socio;
            echo "  ✓ Socio creado/encontrado: {$socio->nombre} {$socio->apellido} ({$data['dni']})\n";
        }

        // 3. Asignar socios al profesor usando la relación many-to-many
        foreach ($socios as $socio) {
            $profesor->sociosAsignados()->syncWithoutDetaching([$socio->id]);
        }

        echo "\n✅ Seeder completado exitosamente\n";
        echo "Profesor: {$profesor->email}\n";
        echo "Socios asignados: " . $profesor->sociosAsignados()->count() . "\n\n";
        echo "Testing:\n";
        echo "1. Login: POST /api/auth/login\n";
        echo "   Body: {\"email\": \"{$profesor->email}\", \"password\": \"profesor123\"}\n\n";
        echo "2. Obtener socios: GET /api/profesor/socios\n";
        echo "   Header: Authorization: Bearer <token>\n\n";
        echo "3. Obtener disponibles: GET /api/profesor/socios/disponibles\n";
        echo "   Header: Authorization: Bearer <token>\n\n";
        echo "4. Asignar socio: POST /api/profesor/socios/{socioId}\n";
        echo "   Header: Authorization: Bearer <token>\n\n";
        echo "5. Desasignar socio: DELETE /api/profesor/socios/{socioId}\n";
        echo "   Header: Authorization: Bearer <token>\n";
    }
}
