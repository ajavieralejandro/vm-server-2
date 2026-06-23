<?php

namespace Tests\Feature\Gym;

use App\Models\Gym\DailyTemplate;
use App\Models\Gym\DailyTemplateExercise;
use App\Models\Gym\Exercise;
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

    protected function admin(): User
    {
        return User::factory()->create([
            'dni' => (string) random_int(10000000, 99999999),
            'password' => 'secret123',
            'user_type' => 'local',
            'is_admin' => true,
            'is_professor' => false,
        ]);
    }

    protected function student(): User
    {
        return User::factory()->create([
            'dni' => (string) random_int(10000000, 99999999),
            'password' => 'secret123',
            'user_type' => 'local',
            'is_professor' => false,
        ]);
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sentadilla goblet',
            'description' => 'Ejercicio base para patrón de sentadilla.',
            'exercise_type' => 'fuerza',
            'category' => 'dominante_rodilla',
            'tags' => ['tren_inferior', 'principiante'],
            'instructions' => 'Mantener columna neutra.',
            'is_active' => true,
        ], $overrides);
    }

    public function test_index_requires_professor(): void
    {
        $this->actingAs($this->student())
            ->getJson('/api/admin/gym/exercises')
            ->assertStatus(403);
    }

    public function test_professor_can_create_exercise_with_valid_type_and_category(): void
    {
        $this->actingAs($this->professor())
            ->postJson('/api/admin/gym/exercises', $this->validPayload([
                'video_url' => 'https://www.youtube.com/watch?v=example',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.name', 'Sentadilla goblet')
            ->assertJsonPath('data.exercise_type', 'fuerza')
            ->assertJsonPath('data.category', 'dominante_rodilla')
            ->assertJsonPath('data.exercise_type_label', 'Fuerza')
            ->assertJsonPath('data.category_label', 'Dominante de rodilla');
    }

    public function test_rejects_category_that_does_not_belong_to_type(): void
    {
        $this->actingAs($this->professor())
            ->postJson('/api/admin/gym/exercises', $this->validPayload([
                'exercise_type' => 'fuerza',
                'category' => 'carrera',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_can_update_exercise_with_video_url(): void
    {
        $professor = $this->professor();
        $this->actingAs($professor);

        $create = $this->postJson('/api/admin/gym/exercises', $this->validPayload())
            ->assertStatus(201)
            ->json('data');

        $this->putJson('/api/admin/gym/exercises/'.$create['id'], [
            'video_url' => 'https://www.youtube.com/watch?v=updated',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.video_url', 'https://www.youtube.com/watch?v=updated');
    }

    public function test_rejects_invalid_video_url(): void
    {
        $this->actingAs($this->professor())
            ->postJson('/api/admin/gym/exercises', $this->validPayload([
                'video_url' => 'not-a-valid-url',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video_url']);
    }

    public function test_can_list_exercises_filtered_by_exercise_type(): void
    {
        $professor = $this->professor();
        $this->actingAs($professor);

        Exercise::create($this->validPayload(['name' => 'Fuerza A']));
        Exercise::create($this->validPayload([
            'name' => 'Resistencia A',
            'exercise_type' => 'resistencia',
            'category' => 'carrera',
        ]));

        $this->getJson('/api/admin/gym/exercises?exercise_type=fuerza')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exercise_type', 'fuerza');
    }

    public function test_can_list_exercises_filtered_by_category(): void
    {
        $professor = $this->professor();
        $this->actingAs($professor);

        Exercise::create($this->validPayload(['name' => 'Rodilla A', 'category' => 'dominante_rodilla']));
        Exercise::create($this->validPayload([
            'name' => 'Cadera A',
            'category' => 'dominante_cadera',
        ]));

        $this->getJson('/api/admin/gym/exercises?category=dominante_cadera')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'dominante_cadera');
    }

    public function test_can_list_exercises_filtered_by_tag(): void
    {
        $professor = $this->professor();
        $this->actingAs($professor);

        Exercise::create($this->validPayload([
            'name' => 'Con tag',
            'tags' => ['potencia'],
        ]));
        Exercise::create($this->validPayload([
            'name' => 'Sin tag potencia',
            'tags' => ['core'],
        ]));

        $this->getJson('/api/admin/gym/exercises?tags[]=potencia')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Con tag');
    }

    public function test_meta_endpoint_returns_types_categories_and_tags(): void
    {
        $response = $this->actingAs($this->professor())
            ->getJson('/api/admin/gym/exercises/meta')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $data = $response->json('data');

        $this->assertNotEmpty($data['types'], 'types no debe estar vacío');
        $this->assertCount(4, $data['types']);
        $this->assertSame('fuerza', $data['types'][0]['value']);
        $this->assertSame('Fuerza', $data['types'][0]['label']);

        $this->assertNotEmpty($data['categories'], 'categories no debe estar vacío');
        $this->assertArrayHasKey('fuerza', $data['categories']);
        $this->assertNotEmpty($data['categories']['fuerza']);
        $this->assertSame('dominante_rodilla', $data['categories']['fuerza'][0]['value']);

        $this->assertArrayHasKey('movilidad', $data['categories']);
        $this->assertArrayHasKey('estabilidad', $data['categories']);
        $this->assertArrayHasKey('resistencia', $data['categories']);

        $this->assertNotEmpty($data['tags'], 'tags no debe estar vacío');
        $this->assertArrayHasKey('value', $data['tags'][0]);
        $this->assertArrayHasKey('label', $data['tags'][0]);
    }

    public function test_student_cannot_create_exercise(): void
    {
        $this->actingAs($this->student())
            ->postJson('/api/admin/gym/exercises', $this->validPayload())
            ->assertStatus(403);
    }

    public function test_cannot_delete_exercise_used_in_template(): void
    {
        $professor = $this->professor();
        $this->actingAs($professor);

        $exercise = Exercise::create($this->validPayload(['name' => 'En plantilla']));

        $template = DailyTemplate::create([
            'title' => 'Plantilla test',
            'goal' => 'strength',
            'created_by' => $professor->id,
        ]);

        DailyTemplateExercise::create([
            'daily_template_id' => $template->id,
            'exercise_id' => $exercise->id,
            'display_order' => 1,
        ]);

        $this->deleteJson('/api/admin/gym/exercises/'.$exercise->id)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'EXERCISE_IN_USE');
    }

    public function test_only_admin_can_force_delete_exercise(): void
    {
        $professor = $this->professor();
        $admin = $this->admin();

        $exercise = Exercise::create($this->validPayload(['name' => 'Force delete']));

        $template = DailyTemplate::create([
            'title' => 'Plantilla force',
            'goal' => 'strength',
            'created_by' => $professor->id,
        ]);

        DailyTemplateExercise::create([
            'daily_template_id' => $template->id,
            'exercise_id' => $exercise->id,
            'display_order' => 1,
        ]);

        $this->actingAs($professor)
            ->deleteJson('/api/admin/gym/exercises/'.$exercise->id.'/force')
            ->assertStatus(403);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/gym/exercises/'.$exercise->id.'/force')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('gym_exercises', ['id' => $exercise->id]);
    }

    public function test_crud_exercise_ok(): void
    {
        $this->actingAs($this->professor());

        $create = $this->postJson('/api/admin/gym/exercises', $this->validPayload([
            'name' => 'Remo con barra',
        ]))
            ->assertStatus(201)
            ->json('data');

        $this->getJson('/api/admin/gym/exercises/'.$create['id'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Remo con barra');

        $this->putJson('/api/admin/gym/exercises/'.$create['id'], [
            'difficulty_level' => 'intermediate',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.difficulty_level', 'intermediate');

        $this->deleteJson('/api/admin/gym/exercises/'.$create['id'])
            ->assertOk()
            ->assertJsonPath('message', 'Ejercicio eliminado correctamente');
    }
}
