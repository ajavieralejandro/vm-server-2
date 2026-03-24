<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\SocioPadron;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfesorSocioTodosTest extends TestCase
{
    use RefreshDatabase;

    public function test_profesor_socios_todos_endpoint()
    {
        $profesor = User::factory()->create(['is_professor' => true]);
        $socio1 = SocioPadron::factory()->create(['apynom' => 'Socio Test 1', 'dni' => '12345678']);
        $socio2 = SocioPadron::factory()->create(['apynom' => 'Socio Test 2', 'dni' => '87654321']);
        // Asignar socio1 al profesor
        \DB::table('professor_socio')->insert(['professor_id' => $profesor->id, 'socio_id' => $socio1->id]);

        $response = $this->actingAs($profesor)->getJson('/api/profesor/socios/todos?search=Socio');
        $response->assertOk()
            ->assertJsonFragment([
                'id' => $socio1->id,
                'apynom' => 'Socio Test 1',
                'dni' => '12345678',
                'is_assigned_to_professor' => true,
                'can_view' => true,
                'can_edit_progress' => false,
                'can_assign_routine' => false,
            ])
            ->assertJsonFragment([
                'id' => $socio2->id,
                'apynom' => 'Socio Test 2',
                'dni' => '87654321',
                'is_assigned_to_professor' => false,
                'can_view' => true,
                'can_edit_progress' => false,
                'can_assign_routine' => false,
            ]);
    }
}
