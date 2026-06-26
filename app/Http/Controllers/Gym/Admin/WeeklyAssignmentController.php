<?php

namespace App\Http\Controllers\Gym\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gym\WeeklyAssignment;
use App\Services\Gym\WeeklyAssignmentService;
use App\Support\Gym\GymAssignmentAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WeeklyAssignmentController extends Controller
{
    public function __construct(
        private WeeklyAssignmentService $weeklyAssignmentService
    ) {}

    /**
     * Lista de asignaciones semanales
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'user_id', 'from', 'to', 'created_by', 'source_type',
            'sort_by', 'sort_direction'
        ]);

        $perPage = min($request->get('per_page', 20), 100);
        $assignments = $this->weeklyAssignmentService->getFilteredAssignments($filters, $perPage);

        return response()->json([
            'data' => $assignments->items(),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'last_page' => $assignments->lastPage(),
            ],
        ]);
    }

    /**
     * Mostrar una asignación específica
     */
    public function show(WeeklyAssignment $weeklyAssignment): JsonResponse
    {
        GymAssignmentAuthorization::abortUnlessCanManageWeeklyAssignment(auth()->user(), $weeklyAssignment);

        $assignment = $this->weeklyAssignmentService->getAssignmentWithDetails($weeklyAssignment);

        return response()->json($assignment);
    }

    /**
     * Crear una nueva asignación semanal
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'week_start' => 'required|date',
            'week_end' => 'required|date|after_or_equal:week_start',
            'source_type' => 'nullable|string|in:from_weekly_template,manual,assistant',
            'weekly_template_id' => 'nullable|integer|exists:gym_weekly_templates,id',
            'notes' => 'nullable|string',
            'days' => 'array',
            'days.*.weekday' => 'required|integer|min:1|max:7',
            'days.*.date' => 'required|date',
            'days.*.title' => 'nullable|string|max:255',
            'days.*.notes' => 'nullable|string',
            'days.*.exercises' => 'array',
            'days.*.exercises.*.exercise_id' => 'nullable|integer|exists:exercises,id',
            'days.*.exercises.*.order' => 'integer|min:1',
            'days.*.exercises.*.name' => 'required|string',
            'days.*.exercises.*.muscle_group' => 'nullable|string',
            'days.*.exercises.*.equipment' => 'nullable|string',
            'days.*.exercises.*.instructions' => 'nullable|string',
            'days.*.exercises.*.tempo' => 'nullable|string',
            'days.*.exercises.*.notes' => 'nullable|string',
            'days.*.exercises.*.sets' => 'array',
            'days.*.exercises.*.sets.*.set_number' => 'integer|min:1',
            'days.*.exercises.*.sets.*.reps_min' => 'nullable|integer|min:1',
            'days.*.exercises.*.sets.*.reps_max' => 'nullable|integer|min:1',
            'days.*.exercises.*.sets.*.rest_seconds' => 'nullable|integer|min:0',
            'days.*.exercises.*.sets.*.tempo' => 'nullable|string',
            'days.*.exercises.*.sets.*.rpe_target' => 'nullable|numeric|min:0|max:10',
            'days.*.exercises.*.sets.*.notes' => 'nullable|string',
        ]);

        // Verificar conflictos de asignación
        $conflicts = $this->weeklyAssignmentService->checkAssignmentConflicts(
            $validated['user_id'],
            $validated['week_start'],
            $validated['week_end']
        );

        if (!empty($conflicts)) {
            return response()->json([
                'message' => 'El usuario ya tiene asignaciones para este período.',
                'conflicts' => $conflicts,
            ], 422);
        }

        try {
            $assignment = $this->weeklyAssignmentService->createWeeklyAssignment(
                $validated, 
                $request->user()
            );

            return response()->json([
                'message' => 'Asignación semanal creada exitosamente.',
                'assignment' => $assignment->fresh(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una asignación semanal
     */
    public function update(Request $request, WeeklyAssignment $weeklyAssignment): JsonResponse
    {
        GymAssignmentAuthorization::abortUnlessCanManageWeeklyAssignment($request->user(), $weeklyAssignment);

        $validated = $request->validate([
            'week_start' => 'sometimes|date',
            'week_end' => 'sometimes|date|after_or_equal:week_start',
            'notes' => 'nullable|string',
            'days' => 'sometimes|array',
            'days.*.weekday' => 'required|integer|min:1|max:7',
            'days.*.date' => 'required|date',
            'days.*.title' => 'nullable|string|max:255',
            'days.*.notes' => 'nullable|string',
            'days.*.exercises' => 'array',
            'days.*.exercises.*.exercise_id' => 'nullable|integer|exists:exercises,id',
            'days.*.exercises.*.order' => 'integer|min:1',
            'days.*.exercises.*.name' => 'required|string',
            'days.*.exercises.*.muscle_group' => 'nullable|string',
            'days.*.exercises.*.equipment' => 'nullable|string',
            'days.*.exercises.*.instructions' => 'nullable|string',
            'days.*.exercises.*.tempo' => 'nullable|string',
            'days.*.exercises.*.notes' => 'nullable|string',
            'days.*.exercises.*.sets' => 'array',
            'days.*.exercises.*.sets.*.set_number' => 'integer|min:1',
            'days.*.exercises.*.sets.*.reps_min' => 'nullable|integer|min:1',
            'days.*.exercises.*.sets.*.reps_max' => 'nullable|integer|min:1',
            'days.*.exercises.*.sets.*.rest_seconds' => 'nullable|integer|min:0',
            'days.*.exercises.*.sets.*.tempo' => 'nullable|string',
            'days.*.exercises.*.sets.*.rpe_target' => 'nullable|numeric|min:0|max:10',
            'days.*.exercises.*.sets.*.notes' => 'nullable|string',
        ]);

        // Verificar conflictos si se cambian las fechas
        if (isset($validated['week_start']) || isset($validated['week_end'])) {
            $conflicts = $this->weeklyAssignmentService->checkAssignmentConflicts(
                $weeklyAssignment->user_id,
                $validated['week_start'] ?? $weeklyAssignment->week_start,
                $validated['week_end'] ?? $weeklyAssignment->week_end,
                $weeklyAssignment->id
            );

            if (!empty($conflicts)) {
                return response()->json([
                    'message' => 'Las nuevas fechas generan conflictos con otras asignaciones.',
                    'conflicts' => $conflicts,
                ], 422);
            }
        }

        try {
            $assignment = $this->weeklyAssignmentService->updateWeeklyAssignment(
                $weeklyAssignment, 
                $validated
            );

            return response()->json([
                'message' => 'Asignación semanal actualizada exitosamente.',
                'assignment' => $assignment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una asignación semanal
     */
    public function destroy(WeeklyAssignment $weeklyAssignment): JsonResponse
    {
        GymAssignmentAuthorization::abortUnlessCanManageWeeklyAssignment(auth()->user(), $weeklyAssignment);

        try {
            $this->weeklyAssignmentService->deleteWeeklyAssignment($weeklyAssignment);

            return response()->json([
                'message' => 'Asignación semanal eliminada exitosamente.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar la asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicar una asignación semanal
     */
    public function duplicate(Request $request, WeeklyAssignment $weeklyAssignment): JsonResponse
    {
        GymAssignmentAuthorization::abortUnlessCanManageWeeklyAssignment($request->user(), $weeklyAssignment);

        $validated = $request->validate([
            'week_start' => 'required|date',
            'week_end' => 'required|date|after_or_equal:week_start',
        ]);

        // Verificar conflictos
        $conflicts = $this->weeklyAssignmentService->checkAssignmentConflicts(
            $weeklyAssignment->user_id,
            $validated['week_start'],
            $validated['week_end']
        );

        if (!empty($conflicts)) {
            return response()->json([
                'message' => 'El usuario ya tiene asignaciones para este período.',
                'conflicts' => $conflicts,
            ], 422);
        }

        try {
            $newAssignment = $this->weeklyAssignmentService->duplicateAssignment(
                $weeklyAssignment,
                $validated['week_start'],
                $validated['week_end'],
                $request->user()
            );

            return response()->json([
                'message' => 'Asignación duplicada exitosamente.',
                'assignment' => $newAssignment->fresh(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al duplicar la asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de asignaciones
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['created_by', 'date_from', 'date_to']);
            
            // Implementación temporal simple para debugging
            $stats = [
                'total_assignments' => \App\Models\Gym\WeeklyAssignment::count(),
                'active_assignments' => \App\Models\Gym\WeeklyAssignment::where('status', 'active')->count(),
                'completed_assignments' => \App\Models\Gym\WeeklyAssignment::where('status', 'completed')->count(),
                'filters_applied' => $filters,
                'timestamp' => now()->toISOString()
            ];

            return response()->json($stats);
            
        } catch (\Exception $e) {
            \Log::error('Error in WeeklyAssignmentController@stats', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'error' => 'Error retrieving assignment statistics',
                'message' => app()->environment('local') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Obtener adherencia de una asignación específica
     */
    public function adherence(WeeklyAssignment $weeklyAssignment): JsonResponse
    {
        GymAssignmentAuthorization::abortUnlessCanManageWeeklyAssignment(auth()->user(), $weeklyAssignment);

        $adherence = $this->weeklyAssignmentService->getAssignmentAdherence($weeklyAssignment);
        
        return response()->json($adherence);
    }
}
