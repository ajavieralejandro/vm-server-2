<?php

namespace Tests\Feature\Gym;

use App\Models\Gym\DailyTemplate;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;
use App\Models\Gym\WeeklyAssignment;
use App\Models\SocioPadron;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfessorOpenStudentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function professor(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'dni' => (string) random_int(10000000, 99999999),
            'password' => 'secret123',
            'user_type' => 'local',
            'is_professor' => true,
            'account_status' => 'active',
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'dni' => (string) random_int(10000000, 99999999),
            'password' => 'secret123',
            'user_type' => 'local',
            'is_admin' => true,
            'account_status' => 'active',
        ]);
    }

    private function enabledSocio(array $overrides = []): SocioPadron
    {
        return SocioPadron::factory()->create(array_merge([
            'apynom' => 'PEREZ, JAVIER',
            'dni' => '36329083',
            'hab_controles' => 1,
            'acceso_full' => true,
        ], $overrides));
    }

    private function dailyTemplate(User $professor): DailyTemplate
    {
        return DailyTemplate::create([
            'created_by' => $professor->id,
            'title' => 'Rutina test',
            'goal' => 'strength',
            'estimated_duration_min' => 45,
            'level' => 'beginner',
            'tags' => ['test'],
        ]);
    }

    private function studentUserFromSocio(SocioPadron $socio): User
    {
        $dni = preg_replace('/\D+/', '', (string) $socio->dni);

        return User::factory()->create([
            'dni' => $dni,
            'name' => (string) $socio->apynom,
            'student_gym' => true,
            'account_status' => 'active',
            'is_professor' => false,
            'is_admin' => false,
            'barcode' => $socio->barcode,
            'socio_id' => $socio->sid,
        ]);
    }

    public function test_professor_can_search_enabled_students_without_professor_socio(): void
    {
        $professor = $this->professor();
        $socio = $this->enabledSocio(['apynom' => 'GARCIA, MARIA', 'dni' => '11112222']);

        $response = $this->actingAs($professor)
            ->getJson('/api/profesor/socios/todos?search=GARCIA');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment([
                'id' => $socio->id,
                'can_assign_routine' => true,
            ]);
    }

    public function test_professor_can_assign_template_to_student_without_professor_socio(): void
    {
        $professor = $this->professor();
        $socio = $this->enabledSocio();
        $student = $this->studentUserFromSocio($socio);
        $template = $this->dailyTemplate($professor);

        $response = $this->actingAs($professor)->postJson('/api/profesor/assign-template', [
            'student_id' => $student->id,
            'daily_template_id' => $template->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('daily_assignments', [
            'daily_template_id' => $template->id,
            'assigned_by' => $professor->id,
            'status' => 'active',
        ]);
    }

    public function test_assigning_template_creates_or_reuses_psa_without_manual_pivot(): void
    {
        $professor = $this->professor();
        $socio = $this->enabledSocio(['dni' => '22223333']);
        $student = $this->studentUserFromSocio($socio);
        $template = $this->dailyTemplate($professor);

        $this->actingAs($professor)->postJson('/api/profesor/assign-template', [
            'student_id' => $student->id,
            'daily_template_id' => $template->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
        ])->assertCreated();

        $this->assertDatabaseHas('professor_student_assignments', [
            'professor_id' => $professor->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('professor_socio', [
            'professor_id' => $professor->id,
            'socio_id' => $socio->id,
        ]);
    }

    public function test_professor_cannot_assign_template_to_disabled_student(): void
    {
        $professor = $this->professor();
        $socio = SocioPadron::factory()->disabled()->create(['dni' => '33334444']);
        $student = User::factory()->create([
            'dni' => '33334444',
            'student_gym' => false,
            'account_status' => 'active',
            'is_professor' => false,
            'is_admin' => false,
        ]);
        $template = $this->dailyTemplate($professor);

        $this->actingAs($professor)->postJson('/api/profesor/assign-template', [
            'student_id' => $student->id,
            'daily_template_id' => $template->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'El socio no está habilitado para recibir rutinas.']);
    }

    public function test_professor_can_edit_own_assignment(): void
    {
        $professor = $this->professor();
        $student = User::factory()->create([
            'student_gym' => true,
            'account_status' => 'active',
            'is_professor' => false,
        ]);
        $template = $this->dailyTemplate($professor);
        $psa = ProfessorStudentAssignment::create([
            'professor_id' => $professor->id,
            'student_id' => $student->id,
            'assigned_by' => $professor->id,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $assignment = TemplateAssignment::create([
            'professor_student_assignment_id' => $psa->id,
            'daily_template_id' => $template->id,
            'assigned_by' => $professor->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
            'status' => 'active',
        ]);

        $this->actingAs($professor)
            ->putJson('/api/profesor/assignments/' . $assignment->id, [
                'professor_notes' => 'Notas propias',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('daily_assignments', [
            'id' => $assignment->id,
            'professor_notes' => 'Notas propias',
        ]);
    }

    public function test_professor_cannot_edit_other_professor_assignment(): void
    {
        $professorA = $this->professor(['dni' => '40000001']);
        $professorB = $this->professor(['dni' => '40000002']);
        $student = User::factory()->create(['student_gym' => true, 'account_status' => 'active']);
        $template = $this->dailyTemplate($professorA);
        $psa = ProfessorStudentAssignment::create([
            'professor_id' => $professorA->id,
            'student_id' => $student->id,
            'assigned_by' => $professorA->id,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $assignment = TemplateAssignment::create([
            'professor_student_assignment_id' => $psa->id,
            'daily_template_id' => $template->id,
            'assigned_by' => $professorA->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
            'status' => 'active',
        ]);

        $this->actingAs($professorB)
            ->putJson('/api/profesor/assignments/' . $assignment->id, [
                'professor_notes' => 'Intento ajeno',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_edit_any_assignment(): void
    {
        $professor = $this->professor();
        $admin = $this->admin();
        $student = User::factory()->create(['student_gym' => true, 'account_status' => 'active']);
        $template = $this->dailyTemplate($professor);
        $psa = ProfessorStudentAssignment::create([
            'professor_id' => $professor->id,
            'student_id' => $student->id,
            'assigned_by' => $professor->id,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $assignment = TemplateAssignment::create([
            'professor_student_assignment_id' => $psa->id,
            'daily_template_id' => $template->id,
            'assigned_by' => $professor->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/profesor/assignments/' . $assignment->id, [
                'professor_notes' => 'Editado por admin',
            ])
            ->assertOk();
    }

    public function test_student_can_see_templates_assigned_without_professor_socio(): void
    {
        $professor = $this->professor();
        $socio = $this->enabledSocio(['dni' => '55556666']);
        $student = $this->studentUserFromSocio($socio);
        $template = $this->dailyTemplate($professor);

        $this->actingAs($professor)->postJson('/api/profesor/assign-template', [
            'student_id' => $student->id,
            'daily_template_id' => $template->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
        ])->assertCreated();

        $response = $this->actingAs($student)->getJson('/api/student/my-templates');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.templates'));
        $this->assertSame('Rutina test', $response->json('data.templates.0.daily_template.title'));
    }

    public function test_legacy_assignment_with_null_assigned_by_still_accessible_by_psa_professor(): void
    {
        $professor = $this->professor();
        $student = User::factory()->create(['student_gym' => true, 'account_status' => 'active']);
        $template = $this->dailyTemplate($professor);
        $psa = ProfessorStudentAssignment::create([
            'professor_id' => $professor->id,
            'student_id' => $student->id,
            'assigned_by' => $professor->id,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $assignment = TemplateAssignment::create([
            'professor_student_assignment_id' => $psa->id,
            'daily_template_id' => $template->id,
            'assigned_by' => $professor->id,
            'start_date' => now()->toDateString(),
            'frequency' => [1, 3, 5],
            'status' => 'active',
        ]);

        try {
            DB::table('daily_assignments')
                ->where('id', $assignment->id)
                ->update(['assigned_by' => null]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('assigned_by es NOT NULL en el esquema actual.');
        }

        $this->actingAs($professor)
            ->getJson('/api/profesor/assignments/' . $assignment->id)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_weekly_assignment_update_destroy_respects_created_by(): void
    {
        $professorA = $this->professor(['dni' => '60000001']);
        $professorB = $this->professor(['dni' => '60000002']);
        $student = User::factory()->create(['student_gym' => true, 'account_status' => 'active']);

        $this->actingAs($professorA);
        $created = $this->postJson('/api/admin/gym/weekly-assignments', [
            'user_id' => $student->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->endOfWeek()->toDateString(),
            'source_type' => 'manual',
            'days' => [[
                'weekday' => 1,
                'date' => now()->startOfWeek()->toDateString(),
                'title' => 'Dia 1',
                'exercises' => [[
                    'order' => 1,
                    'name' => 'Sentadilla',
                    'sets' => [['set_number' => 1, 'reps_min' => 8, 'reps_max' => 8]],
                ]],
            ]],
        ])->assertCreated()->json('assignment');

        $assignmentId = $created['id'];

        $this->actingAs($professorB)
            ->putJson('/api/admin/gym/weekly-assignments/' . $assignmentId, [
                'notes' => 'No deberia poder',
            ])
            ->assertForbidden();

        $this->actingAs($professorA)
            ->putJson('/api/admin/gym/weekly-assignments/' . $assignmentId, [
                'notes' => 'Notas propias',
            ])
            ->assertOk()
            ->assertJsonFragment(['notes' => 'Notas propias']);

        $this->actingAs($professorB)
            ->deleteJson('/api/admin/gym/weekly-assignments/' . $assignmentId)
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/gym/weekly-assignments/' . $assignmentId)
            ->assertOk();
    }
}
