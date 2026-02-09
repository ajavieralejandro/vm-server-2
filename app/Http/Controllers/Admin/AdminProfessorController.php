<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SocioPadron;
use App\Services\Admin\ProfessorManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class AdminProfessorController extends Controller
{
    public function __construct(
        private ProfessorManagementService $professorManagementService
    ) {}

    /**
     * Lista de profesores con estadísticas
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'search', 'account_status', 'has_students',
                'sort_by', 'sort_direction'
            ]);

            // Implementación temporal simple para debugging
            $professors = \App\Models\User::where('is_professor', true)->get();

            // Transformación básica sin stats complejos
            $professorsData = $professors->map(function ($professor) {
                return [
                    'id' => $professor->id,
                    'name' => $professor->name,
                    'email' => $professor->email,
                    'dni' => $professor->dni,
                    'account_status' => $professor->account_status ?? 'active',
                    'professor_since' => $professor->professor_since ?? $professor->created_at,
                    'stats' => [
                        'students_count' => 0,
                        'active_assignments' => 0,
                        'templates_created' => 0,
                        'total_assignments' => 0,
                    ],
                    'specialties' => [],
                    'permissions' => $professor->permissions ?? [],
                ];
            });

            return response()->json([
                'professors' => $professorsData,
                'summary' => [
                    'total_professors' => $professors->count(),
                    'active_professors' => $professors->where('account_status', 'active')->count(),
                    'total_students_assigned' => 0,
                    'avg_students_per_professor' => 0,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in AdminProfessorController@index', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'message' => 'Error retrieving professors',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Mostrar un profesor específico
     */
    public function show(User $professor): JsonResponse
    {
        if (!$professor->is_professor) {
            return response()->json([
                'message' => 'User is not a professor.'
            ], 404);
        }

        $stats = $professor->getProfessorStats();
        $recentActivity = $professor->getRecentActivity(15);
        $qualifications = $this->professorManagementService->parseQualifications($professor->admin_notes);

        return response()->json([
            'professor' => [
                'basic_info' => [
                    'id' => $professor->id,
                    'name' => $professor->display_name,
                    'email' => $professor->email,
                    'dni' => $professor->dni,
                    'avatar_url' => $professor->avatar_url,
                    'professor_since' => $professor->professor_since,
                    'account_status' => $professor->account_status,
                ],
                'qualifications' => $qualifications,
                'permissions' => $professor->permissions ?? [],
                'stats' => $stats,
                'recent_activity' => $recentActivity,
            ]
        ]);
    }

    /**
     * Asignar rol de profesor a un usuario
     */
    public function assignProfessor(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'qualifications' => 'required|array',
            'qualifications.education' => 'required|string|max:255',
            'qualifications.certifications' => 'nullable|array',
            'qualifications.certifications.*' => 'string|max:255',
            'qualifications.experience_years' => 'required|integer|min:0|max:50',
            'qualifications.specialties' => 'nullable|array',
            'qualifications.specialties.*' => 'string|in:strength,hypertrophy,endurance,mobility,rehabilitation,functional,crossfit,yoga,pilates',
            'permissions' => 'nullable|array',
            'permissions.can_create_templates' => 'boolean',
            'permissions.can_assign_routines' => 'boolean',
            'permissions.can_view_all_students' => 'boolean',
            'permissions.can_export_data' => 'boolean',
            'permissions.max_students' => 'nullable|integer|min:1|max:200',
            'schedule' => 'nullable|array',
            'schedule.available_days' => 'nullable|array',
            'schedule.available_days.*' => 'integer|min:1|max:7',
            'schedule.start_time' => 'nullable|string',
            'schedule.end_time' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $professor = $this->professorManagementService->assignProfessorRole(
                $user,
                $validated,
                $request->user()
            );

            return response()->json([
                'message' => 'Rol de profesor asignado exitosamente.',
                'professor' => $professor->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Actualizar información de un profesor
     */
    public function update(Request $request, User $professor): JsonResponse
    {
        $validated = $request->validate([
            'qualifications' => 'sometimes|array',
            'qualifications.education' => 'sometimes|string|max:255',
            'qualifications.certifications' => 'nullable|array',
            'qualifications.certifications.*' => 'string|max:255',
            'qualifications.experience_years' => 'sometimes|integer|min:0|max:50',
            'qualifications.specialties' => 'nullable|array',
            'qualifications.specialties.*' => 'string|in:strength,hypertrophy,endurance,mobility,rehabilitation,functional,crossfit,yoga,pilates',
            'permissions' => 'nullable|array',
            'permissions.can_create_templates' => 'boolean',
            'permissions.can_assign_routines' => 'boolean',
            'permissions.can_view_all_students' => 'boolean',
            'permissions.can_export_data' => 'boolean',
            'permissions.max_students' => 'nullable|integer|min:1|max:200',
            'schedule' => 'nullable|array',
            'notes' => 'nullable|string|max:1000',
            'account_status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);

        try {
            $professor = $this->professorManagementService->updateProfessor(
                $professor,
                $validated,
                $request->user()
            );

            return response()->json([
                'message' => 'Información del profesor actualizada exitosamente.',
                'professor' => $professor->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Remover rol de profesor
     */
    public function removeProfessor(Request $request, User $professor): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'reassign_students_to' => 'nullable|integer|exists:users,id',
        ]);

        try {
            $result = $this->professorManagementService->removeProfessorRole(
                $professor,
                $validated,
                $request->user()
            );

            return response()->json([
                'message' => 'Rol de profesor removido exitosamente.',
                'reassigned_assignments' => $result['reassigned_assignments'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'active_assignments' => $e->getCode() === 422 ?
                    \App\Models\Gym\WeeklyAssignment::where('created_by', $professor->id)
                        ->where('week_end', '>=', now())->count() : null,
            ], 422);
        }
    }

    /**
     * Obtener estudiantes (SOCIOS) asignables a un profesor
     * ✅ Ahora usa socios_padron (SocioPadron) en vez de users.
     */
    public function students(Request $request, User $professor): JsonResponse
    {
        try {
            if (!$professor->is_professor) {
                return response()->json([
                    'message' => 'User is not a professor.'
                ], 404);
            }

            $perPage = (int) $request->input('per_page', 50);
            $search  = trim((string) $request->input('search', $request->input('q', '')));

            $query = SocioPadron::query()
                ->select([
                    'id',
                    'dni',
                    'sid',
                    'apynom',
                    'barcode',
                    'saldo',
                    'semaforo',
                    'ult_impago',
                    'acceso_full',
                    'hab_controles',
                    'updated_at',
                ]);

            if ($search !== '') {
                $query->where(function ($w) use ($search) {
                    $w->where('dni', 'like', "%{$search}%")
                      ->orWhere('sid', 'like', "%{$search}%")
                      ->orWhere('apynom', 'like', "%{$search}%");
                });
            }

            // Filtros opcionales
            if ($request->has('acceso_full')) {
                $query->where('acceso_full', (int) $request->input('acceso_full'));
            }
            if ($request->has('hab_controles')) {
                $query->where('hab_controles', (int) $request->input('hab_controles'));
            }

            $paginator = $query
                ->orderBy('apynom')
                ->paginate($perPage);

            $studentsData = collect($paginator->items())->map(function (SocioPadron $s) use ($professor) {
                return [
                    // ⚠️ OJO: este id es de socios_padron, NO users.id
                    'id' => $s->id,
                    'name' => $s->apynom,
                    'email' => null,
                    'dni' => $s->dni,
                    'account_status' => ($s->acceso_full ? 'active' : 'inactive'),
                    'assigned_date' => $s->updated_at,
                    'professor_id' => $professor->id,
                    'status' => 'available',

                    // Extra útil para el frontend
                    'sid' => $s->sid,
                    'barcode' => $s->barcode,
                    'saldo' => $s->saldo,
                    'semaforo' => $s->semaforo,
                    'ult_impago' => $s->ult_impago,
                    'acceso_full' => (bool) $s->acceso_full,
                    'hab_controles' => (int) $s->hab_controles,
                ];
            });

            return response()->json([
                'students' => $studentsData,
                'total' => $paginator->total(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                ],
                'professor' => [
                    'id' => $professor->id,
                    'name' => $professor->name,
                    'email' => $professor->email
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in AdminProfessorController@students', [
                'error' => $e->getMessage(),
                'professor_id' => $professor->id ?? 'unknown'
            ]);

            return response()->json([
                'message' => 'Error retrieving students',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Reasignar estudiante a otro profesor
     * (OJO: este método sigue usando users.id; si querés reasignar SOCIOS de padron,
     * hay que adaptar el modelo/tablas de asignación.)
     */
    public function reassignStudent(Request $request, User $professor): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:users,id',
            'new_professor_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $reassignedCount = $this->professorManagementService->reassignStudent(
                $professor,
                $validated['student_id'],
                $validated['new_professor_id'],
                $validated['reason'] ?? null
            );

            return response()->json([
                'message' => 'Estudiante reasignado exitosamente.',
                'assignments_reassigned' => $reassignedCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
