<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\Gym\WeeklyAssignment;
use App\Services\Core\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProfessorManagementService
{
    public function __construct(
        private AuditService $auditService
    ) {}

    /**
     * Obtiene lista de profesores con filtros
     */
    public function getFilteredProfessors(array $filters): Collection
    {
        try {
            // Consulta más simple y robusta
            $query = User::where('is_professor', true);
            
            // Aplicar filtros de forma segura
            $this->applyFilters($query, $filters);
            $this->applySorting($query, $filters);

            // Obtener resultados
            $professors = $query->get();
            
            \Log::info('Professors retrieved successfully', [
                'count' => $professors->count(),
                'filters' => $filters
            ]);
            
            return $professors;
            
        } catch (\Exception $e) {
            \Log::error('Error in getFilteredProfessors', [
                'error' => $e->getMessage(),
                'filters' => $filters,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Retornar colección vacía en caso de error
            return collect([]);
        }
    }

    /**
     * Aplica filtros a la consulta de profesores
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['account_status'])) {
            $query->where('account_status', $filters['account_status']);
        }

        if (isset($filters['has_students'])) {
            $hasStudents = $filters['has_students'];
            if ($hasStudents) {
                $query->whereHas('createdAssignments');
            } else {
                $query->whereDoesntHave('createdAssignments');
            }
        }
    }

    /**
     * Aplica ordenamiento a la consulta
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'professor_since';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        
        $allowedSorts = ['name', 'professor_since', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        }
    }

    /**
     * Transforma profesores con estadísticas
     */
    public function transformProfessorsWithStats(Collection $professors): Collection
    {
        return $professors->map(function ($professor) {
            try {
                // Verificar que es un objeto User válido
                if (!$professor instanceof \App\Models\User) {
                    \Log::warning('Invalid professor object in transformProfessorsWithStats', [
                        'type' => gettype($professor),
                        'class' => get_class($professor)
                    ]);
                    return null;
                }
                
                $stats = $professor->getProfessorStats();
                
                // Obtener último token de forma segura
                $lastToken = null;
                try {
                    $lastToken = $professor->tokens()->latest()->first();
                } catch (\Exception $e) {
                    \Log::warning('Error getting professor tokens', [
                        'professor_id' => $professor->id,
                        'error' => $e->getMessage()
                    ]);
                }
                
                return [
                    'id' => $professor->id,
                    'name' => $professor->display_name ?? $professor->name,
                    'email' => $professor->email,
                    'dni' => $professor->dni,
                    'avatar_url' => $professor->avatar_url ?? null,
                    'professor_since' => $professor->professor_since ?? null,
                    'account_status' => $professor->account_status ?? 'active',
                    'last_login' => $lastToken?->last_used_at ?? null,
                    'stats' => [
                        'students_count' => $stats['students_count'] ?? 0,
                        'active_assignments' => $stats['active_assignments'] ?? 0,
                        'templates_created' => $stats['templates_created'] ?? 0,
                        'total_assignments' => $stats['total_assignments'] ?? 0,
                    ],
                    'specialties' => $this->extractSpecialties($professor->admin_notes ?? null),
                    'permissions' => $professor->permissions ?? [],
                ];
                
            } catch (\Exception $e) {
                \Log::error('Error transforming professor', [
                    'professor_id' => $professor->id ?? 'unknown',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                
                // Retornar estructura básica en caso de error
                return [
                    'id' => $professor->id ?? 0,
                    'name' => $professor->name ?? 'Unknown',
                    'email' => $professor->email ?? '',
                    'dni' => $professor->dni ?? '',
                    'avatar_url' => null,
                    'professor_since' => null,
                    'account_status' => 'active',
                    'last_login' => null,
                    'stats' => [
                        'students_count' => 0,
                        'active_assignments' => 0,
                        'templates_created' => 0,
                        'total_assignments' => 0,
                    ],
                    'specialties' => [],
                    'permissions' => [],
                ];
            }
        })->filter(); // Remover elementos null
    }

    /**
     * Asigna rol de profesor a un usuario
     */
    public function assignProfessorRole(User $user, array $data, User $assigner): User
    {
        if ($user->is_professor) {
            throw new \Exception('User is already a professor.');
        }

        // Preparar notas estructuradas
        $structuredNotes = [
            'qualifications' => $data['qualifications'] ?? [],
            'permissions' => $data['permissions'] ?? [],
            'schedule' => $data['schedule'] ?? null,
            'notes' => $data['notes'] ?? null,
            'assigned_by' => $assigner->id,
            'assigned_at' => now()->toISOString(),
        ];

        $user->assignProfessorRole([
            'notes' => json_encode($structuredNotes),
        ]);

        // Log de auditoría
        $this->auditService->logProfessorAssignment($user->id, $structuredNotes);

        return $user;
    }

    /**
     * Actualiza información de un profesor
     */
    public function updateProfessor(User $professor, array $data, User $updater): User
    {
        if (!$professor->is_professor) {
            throw new \Exception('User is not a professor.');
        }

        $oldNotes = $professor->admin_notes;
        $currentData = $oldNotes ? json_decode($oldNotes, true) : [];

        // Actualizar datos estructurados
        if (isset($data['qualifications'])) {
            $currentData['qualifications'] = array_merge(
                $currentData['qualifications'] ?? [],
                $data['qualifications']
            );
        }

        if (isset($data['permissions'])) {
            $currentData['permissions'] = $data['permissions'];
        }

        if (isset($data['schedule'])) {
            $currentData['schedule'] = $data['schedule'];
        }

        if (isset($data['notes'])) {
            $currentData['notes'] = $data['notes'];
        }

        $currentData['updated_by'] = $updater->id;
        $currentData['updated_at'] = now()->toISOString();

        $updateData = [
            'admin_notes' => json_encode($currentData),
        ];

        if (isset($data['account_status'])) {
            $updateData['account_status'] = $data['account_status'];
        }

        $professor->update($updateData);

        // Log de auditoría
        $this->auditService->logUpdate('user', $professor->id, ['admin_notes' => $oldNotes], $updateData);

        return $professor;
    }

    /**
     * Remueve rol de profesor
     */
    public function removeProfessorRole(User $professor, array $data, User $remover): array
    {
        if (!$professor->is_professor) {
            throw new \Exception('User is not a professor.');
        }

        // Verificar si tiene asignaciones activas
        $activeAssignments = WeeklyAssignment::where('created_by', $professor->id)
            ->where('week_end', '>=', now())
            ->count();

        if ($activeAssignments > 0 && !isset($data['reassign_students_to'])) {
            throw new \Exception('Professor has active assignments. Please specify another professor to reassign students to.');
        }

        // Reasignar estudiantes si se especifica
        if (isset($data['reassign_students_to'])) {
            $newProfessor = User::find($data['reassign_students_to']);
            if (!$newProfessor || !$newProfessor->is_professor) {
                throw new \Exception('Invalid professor specified for reassignment.');
            }

            WeeklyAssignment::where('created_by', $professor->id)
                ->where('week_end', '>=', now())
                ->update(['created_by' => $newProfessor->id]);
        }

        $professor->removeProfessorRole();

        // Actualizar notas con información de remoción
        $removalInfo = [
            'removed_by' => $remover->id,
            'removed_at' => now()->toISOString(),
            'reason' => $data['reason'] ?? null,
            'students_reassigned_to' => $data['reassign_students_to'] ?? null,
        ];

        $professor->update([
            'admin_notes' => json_encode($removalInfo),
        ]);

        // Log de auditoría
        $this->auditService->logRoleRemoval($professor->id, 'professor');

        return [
            'professor' => $professor,
            'reassigned_assignments' => $activeAssignments,
        ];
    }

    /**
     * Obtiene estudiantes de un profesor
     */
    public function getProfessorStudents(User $professor): Collection
    {
        if (!$professor->is_professor) {
            throw new \Exception('User is not a professor.');
        }

        return WeeklyAssignment::where('created_by', $professor->id)
            ->with(['user:id,name,dni,avatar_url,email'])
            ->select('user_id', 'created_by')
            ->selectRaw('MIN(created_at) as assigned_since')
            ->selectRaw('MAX(week_end) as last_assignment_end')
            ->selectRaw('COUNT(*) as total_assignments')
            ->groupBy('user_id', 'created_by')
            ->get()
            ->map(function ($assignment) {
                $user = $assignment->user;
                
                // Calcular adherencia promedio
                $adherenceData = WeeklyAssignment::where('user_id', $user->id)
                    ->where('created_by', $assignment->created_by)
                    ->whereNotNull('adherence_percentage')
                    ->avg('adherence_percentage');

                return [
                    'id' => $user->id,
                    'name' => $user->display_name,
                    'dni' => $user->dni,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'assigned_since' => $assignment->assigned_since,
                    'last_assignment_end' => $assignment->last_assignment_end,
                    'total_assignments' => $assignment->total_assignments,
                    'adherence_rate' => round($adherenceData ?? 0, 1),
                    'status' => $assignment->last_assignment_end >= now() ? 'active' : 'inactive',
                ];
            });
    }

    /**
     * Reasigna estudiante a otro profesor
     */
    public function reassignStudent(User $professor, int $studentId, int $newProfessorId, ?string $reason = null): int
    {
        $newProfessor = User::find($newProfessorId);
        if (!$newProfessor->is_professor) {
            throw new \Exception('Target user is not a professor.');
        }

        $reassignedCount = WeeklyAssignment::where('created_by', $professor->id)
            ->where('user_id', $studentId)
            ->where('week_end', '>=', now())
            ->update(['created_by' => $newProfessor->id]);

        // Log de auditoría
        $this->auditService->log(
            action: 'reassign_student',
            resourceType: 'weekly_assignment',
            details: [
                'student_id' => $studentId,
                'from_professor_id' => $professor->id,
                'to_professor_id' => $newProfessor->id,
                'reason' => $reason,
                'assignments_reassigned' => $reassignedCount,
            ],
            severity: 'medium',
            category: 'user_management'
        );

        return $reassignedCount;
    }

    /**
     * Obtiene resumen de profesores
     */
    public function getProfessorsSummary(Collection $professors): array
    {
        return [
            'total_professors' => $professors->count(),
            'active_professors' => $professors->where('account_status', 'active')->count(),
            'total_students_assigned' => WeeklyAssignment::distinct('user_id')->count(),
            'total_active_assignments' => WeeklyAssignment::where('week_end', '>=', now())->count(),
        ];
    }

    /**
     * Extrae especialidades de las notas del profesor
     */
    private function extractSpecialties(?string $notes): array
    {
        if (!$notes) {
            return [];
        }

        $data = json_decode($notes, true);
        return $data['qualifications']['specialties'] ?? [];
    }

    /**
     * Parsea calificaciones de las notas estructuradas
     */
    public function parseQualifications(?string $notes): array
    {
        if (!$notes) {
            return [];
        }

        $data = json_decode($notes, true);
        return $data['qualifications'] ?? [];
    }
}
