<?php

namespace Tests\Feature\Gym;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminExerciseTest extends TestCase
{
    use RefreshDatabase;

    protected function professor(): User
    {
        return User::factory()->create([
            'dni' => (string) random_int(10000000, 99999999),
            'password' => 'secret123',
            'user_type' => 'local',
            'is_professor' => true,
        ]);
    }

    public function test_index_requires_professor(): void
    {
        $user = User::factory()->create([
            'dni' => (string) random_int(10000000, 99999999),
            'password' => 'secret123',
            'user_type' => 'local',
            'is_professor' => false,
        ]);
        $this->actingAs($user)
            ->getJson('/api/admin/gym/exercises')
            ->assertStatus(403);
    }

    public function test_crud_exercise_ok(): void
    {
        $this->actingAs($this->professor());

        // Create
        $payload = [
            'name' => 'Remo con barra',
            'muscle_group' => 'Espalda',
            'equipment' => 'Barra',
            'tags' => ['barbell','back']
        ];
        $create = $this->postJson('/api/admin/gym/exercises', $payload)
            ->assertStatus(201)
            ->json();

        $this->getJson('/api/admin/gym/exercises/'.$create['id'])
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Remo con barra']);

        // Update
        $this->putJson('/api/admin/gym/exercises/'.$create['id'], ['difficulty_level' => 'intermediate'])
            ->assertStatus(200)
            ->assertJsonFragment(['difficulty_level' => 'intermediate']);

        // Delete
        $this->deleteJson('/api/admin/gym/exercises/'.$create['id'])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Ejercicio eliminado correctamente']);
    }
}
