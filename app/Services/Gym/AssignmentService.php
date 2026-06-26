<?php

namespace App\Services\Gym;

use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;
use App\Models\Gym\AssignmentProgress;
use App\Models\User;
use App\Support\Gym\GymAssignmentAuthorization;
use App\Support\Gym\GymStudentEligibility;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class AssignmentService
{
    /**
     * ADMIN FUNCTIONS - Gestión de asignaciones profesor-estudiante
     */

    /**
     * Asignar estudiante(s) a un profesor
     */
    public function assignStudentToProfessor(array $data): ProfessorStudentAssignment
    {
        return DB::transaction(function () use ($data) {
            $professor = User::find($data['professor_id']);
            if (!$professor || !$professor->is_professor) {
                throw new \Exception('Profesor no válido');
            }

            $student = User::find($data['student_id']);
            if (!$student || $student->is_professor || $student->is_admin) {
                throw new \Exception('Estudiante no válido');
            }

            if (!GymStudentEligibility::isUserEnabled($student)) {
                throw new \Exception('El estudiante no está habilitado para recibir rutinas.');
            }

            $existingPair = ProfessorStudentAssignment::query()
                ->where('professor_id', $data['professor_id'])
                ->where('student_id', $data['student_id'])
                ->first();

            if ($existingPair) {
                if ($existingPair->status !== 'active') {
                    $existingPair->update([
                        'status' => 'active',
                        'end_date' => null,
                        'assigned_by' => $data['assigned_by'] ?? $existingPair->assigned_by,
                    ]);
                }

                return $existingPair->fresh(['professor', 'student', 'assignedBy']);
            }

            $assignment = ProfessorStudentAssignment::create($data);

            return $assignment->load(['professor', 'student', 'assignedBy']);
        });
    }

    /**
     * Crear o reutilizar PSA profesor-alumno (sin exigir professor_socio).
     */
    public function ensureProfessorStudentAssignment(int $professorId, int $studentId, ?int $assignedBy = null): ProfessorStudentAssignment
    {
        $student = User::findOrFail($studentId);

        if (!GymStudentEligibility::isUserEnabled($student)) {
            throw new \Exception('El socio no está habilitado para recibir rutinas.');
        }

        $assignedBy = $assignedBy ?? $professorId;

        return DB::transaction(function () use ($professorId, $studentId, $assignedBy) {
            $psa = ProfessorStudentAssignment::query()->firstOrCreate(
                ['professor_id' => $professorId, 'student_id' => $studentId],
                [
                    'assigned_by' => $assignedBy,
                    'status' => 'active',
                    'start_date' => now()->toDateString(),
                ]
            );

            if ($psa->status !== 'active') {
                $psa->update([
                    'status' => 'active',
                    'end_date' => null,
                ]);
            }

            return $psa->fresh();
        });
    }

    /**
     * Obtener todas las asignaciones profesor-estudiante (Admin)
     */
    public function getAllProfessorStudentAssignments(array $filters = []): LengthAwarePaginator
    {
        $query = ProfessorStudentAssignment::with(['professor', 'student', 'assignedBy']);

        // Aplicar filtros
        if (!empty($filters['professor_id'])) {
            $query->where('professor_id', $filters['professor_id']);
        }

        if (!empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->whereHas('professor', function($subQ) use ($filters) {
                    $subQ->where('name', 'like', "%{$filters['search']}%")
                         ->orWhere('email', 'like', "%{$filters['search']}%");
                })->orWhereHas('student', function($subQ) use ($filters) {
                    $subQ->where('name', 'like', "%{$filters['search']}%")
                         ->orWhere('email', 'like', "%{$filters['search']}%");
                });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Obtener estudiantes de un profesor específico
     */
    public function getProfessorStudents($professorId, array $filters = []): LengthAwarePaginator
    {
        $query = ProfessorStudentAssignment::with(['student', 'assignedBy', 'templateAssignments.dailyTemplate'])
            ->where('professor_id', $professorId);

        // Aplicar filtros
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->whereHas('student', function($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('email', 'like', "%{$filters['search']}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Obtener estudiantes sin asignar
     */
    public function getUnassignedStudents(): Collection
    {
        $assignedStudentIds = ProfessorStudentAssignment::where('status', 'active')
            ->pluck('student_id');

        return User::where('is_professor', false)
            ->where('is_admin', false)
            ->whereNotIn('id', $assignedStudentIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * PROFESSOR FUNCTIONS - Gestión de plantillas para estudiantes
     */

    /**
     * Asignar plantilla a estudiante (Profesor)
     */
    public function assignTemplateToStudent(array $data): TemplateAssignment
    {
        return DB::transaction(function () use ($data) {
            $professorId = (int) auth()->id();

            if (!empty($data['student_id']) && empty($data['professor_student_assignment_id'])) {
                $psa = $this->ensureProfessorStudentAssignment(
                    $professorId,
                    (int) $data['student_id'],
                    $professorId
                );
                $data['professor_student_assignment_id'] = $psa->id;
            }

            $professorStudentAssignment = ProfessorStudentAssignment::find($data['professor_student_assignment_id'] ?? null);

            if (!$professorStudentAssignment || $professorStudentAssignment->status !== 'active') {
                throw new \Exception('Asignación profesor-estudiante no válida o inactiva');
            }

            if ((int) $professorStudentAssignment->professor_id !== $professorId) {
                throw new \Exception('No tienes permisos para asignar plantillas a este estudiante');
            }

            if (!GymStudentEligibility::isUserEnabled($professorStudentAssignment->student)) {
                throw new \Exception('El socio no está habilitado para recibir rutinas.');
            }

            $data['assigned_by'] = $data['assigned_by'] ?? $professorId;

            if (empty($data['start_date'])) {
                $data['start_date'] = now()->toDateString();
            }

            if (!array_key_exists('frequency', $data) || $data['frequency'] === null) {
                $data['frequency'] = [1, 3, 5];
            }

            $assignment = TemplateAssignment::create($data);

            $this->generateProgressSchedule($assignment);

            return $assignment->load(['dailyTemplate', 'professorStudentAssignment.student']);
        });
    }

    /**
     * Generar cronograma de progreso basado en frecuencia
     */
    private function generateProgressSchedule(TemplateAssignment $assignment): void
    {
        $startDate = $assignment->start_date;
        $endDate = $assignment->end_date ?? Carbon::parse($startDate)->addWeeks(4); // 4 semanas por defecto
        $frequency = $assignment->frequency; // [1,3,5] = Lun, Mie, Vie (0=Dom, 1=Lun, etc.)

        $currentDate = Carbon::parse($startDate);
        $progressEntries = [];

        while ($currentDate <= $endDate) {
            // Verificar si el día actual está en la frecuencia
            if (in_array($currentDate->dayOfWeek, $frequency)) {
                $progressEntries[] = [
                    'daily_assignment_id' => $assignment->id,
                    'scheduled_date' => $currentDate->format('Y-m-d'),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $currentDate->addDay();
        }

        // Insertar en lotes para mejor performance
        if (!empty($progressEntries)) {
            AssignmentProgress::insert($progressEntries);
        }
    }

    /**
     * Obtener asignaciones de plantillas de un estudiante específico
     */
    public function getStudentTemplateAssignments($studentId, array $filters = []): Collection
    {
        $query = TemplateAssignment::with([
            'dailyTemplate.exercises.exercise',
            'professorStudentAssignment.professor',
            'progress',
        ])->forStudent($studentId);

        if (!empty($filters['professor_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->assignedByProfessor((int) $filters['professor_id'])
                    ->orWhereHas('professorStudentAssignment', function ($sub) use ($filters) {
                        $sub->where('professor_id', (int) $filters['professor_id']);
                    });
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['active_only'])) {
            $query->active();
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * PROGRESS FUNCTIONS - Gestión de progreso
     */

    /**
     * Marcar sesión como completada
     */
    public function markSessionCompleted($progressId, array $data): AssignmentProgress
    {
        $progress = AssignmentProgress::findOrFail($progressId);

        // Validar que el estudiante autenticado sea el dueño
        $studentId = $progress->templateAssignment->professorStudentAssignment->student_id;
        if ($studentId !== auth()->id()) {
            throw new \Exception('No tienes permisos para marcar esta sesión');
        }

        $progress->markAsCompleted(
            $data['exercise_progress'] ?? [],
            $data['student_notes'] ?? null
        );

        return $progress->fresh();
    }

    /**
     * Agregar feedback del profesor
     */
    public function addProfessorFeedback($progressId, string $feedback, ?float $rating = null): AssignmentProgress
    {
        $progress = AssignmentProgress::findOrFail($progressId);

        $templateAssignment = $progress->templateAssignment;
        $psa = $templateAssignment->professorStudentAssignment;
        $row = (object) [
            'assigned_by' => $templateAssignment->assigned_by,
            'psa_professor_id' => $psa?->professor_id,
        ];

        if (!GymAssignmentAuthorization::canManageDailyAssignment(auth()->user(), $row)) {
            throw new \Exception('No tienes permisos para dar feedback a esta sesión');
        }

        $progress->addProfessorFeedback($feedback, $rating);

        return $progress->fresh();
    }

    /**
     * STATISTICS FUNCTIONS - Métricas y reportes
     */

    /**
     * Obtener estadísticas del profesor
     */
    public function getProfessorStats($professorId): array
    {
        $totalStudents = ProfessorStudentAssignment::forProfessor($professorId)->active()->count();
        
        $totalAssignments = TemplateAssignment::forProfessor($professorId)->active()->count();
        
        $completedSessions = AssignmentProgress::whereHas('templateAssignment', function($q) use ($professorId) {
            $q->forProfessor($professorId);
        })->completed()->count();
        
        $pendingSessions = AssignmentProgress::whereHas('templateAssignment', function($q) use ($professorId) {
            $q->forProfessor($professorId);
        })->pending()->count();

        return [
            'total_students' => $totalStudents,
            'total_assignments' => $totalAssignments,
            'completed_sessions' => $completedSessions,
            'pending_sessions' => $pendingSessions,
            'completion_rate' => $completedSessions + $pendingSessions > 0 
                ? round(($completedSessions / ($completedSessions + $pendingSessions)) * 100, 1)
                : 0
        ];
    }

    /**
     * Obtener estadísticas generales (Admin)
     */
    public function getGeneralStats(): array
    {
        $totalProfessors = User::where('is_professor', true)->count();
        $totalStudents = User::where('is_professor', false)->where('is_admin', false)->count();
        $activeAssignments = ProfessorStudentAssignment::active()->count();
        $unassignedStudents = $this->getUnassignedStudents()->count();

        return [
            'total_professors' => $totalProfessors,
            'total_students' => $totalStudents,
            'active_assignments' => $activeAssignments,
            'unassigned_students' => $unassignedStudents,
            'assignment_rate' => $totalStudents > 0 
                ? round(($activeAssignments / $totalStudents) * 100, 1)
                : 0
        ];
    }

    /**
     * UTILITY FUNCTIONS - Funciones auxiliares
     */

    /**
     * Pausar asignación profesor-estudiante
     */
    public function pauseProfessorStudentAssignment($assignmentId): ProfessorStudentAssignment
    {
        $assignment = ProfessorStudentAssignment::findOrFail($assignmentId);
        $assignment->update(['status' => 'paused']);

        // Pausar también todas las asignaciones de plantillas activas
        $assignment->templateAssignments()->active()->update(['status' => 'paused']);

        return $assignment->fresh();
    }

    /**
     * Reactivar asignación profesor-estudiante
     */
    public function reactivateProfessorStudentAssignment($assignmentId): ProfessorStudentAssignment
    {
        $assignment = ProfessorStudentAssignment::findOrFail($assignmentId);
        $assignment->update(['status' => 'active']);

        return $assignment->fresh();
    }

    /**
     * Completar asignación profesor-estudiante
     */
    public function completeProfessorStudentAssignment($assignmentId): ProfessorStudentAssignment
    {
        $assignment = ProfessorStudentAssignment::findOrFail($assignmentId);
        $assignment->update([
            'status' => 'completed',
            'end_date' => now()->toDateString()
        ]);

        // Completar también todas las asignaciones de plantillas activas
        $assignment->templateAssignments()->active()->update(['status' => 'completed']);

        return $assignment->fresh();
    }
}
