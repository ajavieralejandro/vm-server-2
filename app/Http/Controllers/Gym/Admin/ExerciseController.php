<?php

namespace App\Http\Controllers\Gym\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gym\Exercise;
use App\Services\Gym\ExerciseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExerciseController extends Controller
{
    public function __construct(
        private ExerciseService $exerciseService
    ) {}
    public function index(Request $request)
    {
        $filters = $request->only([
            'search', 'muscle_groups', 'target_muscle_groups',
            'equipment', 'difficulty_level', 'movement_pattern',
            'tags', 'sort_by', 'sort_direction'
        ]);
        
        $perPage = min($request->integer('per_page', 20), 100);
        
        $exercises = $this->exerciseService->getFilteredExercises($filters, $perPage);
        
        return response()->json($exercises);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'muscle_groups' => 'nullable|array',
            'muscle_groups.*' => 'string',
            'target_muscle_groups' => 'nullable|array',
            'target_muscle_groups.*' => 'string',
            'movement_pattern' => 'nullable|string|max:255',
            'equipment' => 'nullable|string|max:255',
            'difficulty_level' => 'nullable|string|in:beginner,intermediate,advanced',
            'exercise_type' => 'nullable|string', // Campo ignorado por ahora
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'instructions' => 'nullable|string',
        ]);

        $exercise = $this->exerciseService->createExercise($data, $request->user());
        return response()->json($exercise, 201);
    }

    public function show(Exercise $exercise)
    {
        return response()->json($exercise);
    }

    public function update(Request $request, Exercise $exercise)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'muscle_groups' => 'nullable|array',
            'muscle_groups.*' => 'string',
            'target_muscle_groups' => 'nullable|array',
            'target_muscle_groups.*' => 'string',
            'movement_pattern' => 'nullable|string|max:255',
            'equipment' => 'nullable|string|max:255',
            'difficulty_level' => 'nullable|string|in:beginner,intermediate,advanced',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'instructions' => 'nullable|string',
        ]);

        $exercise = $this->exerciseService->updateExercise($exercise, $data, $request->user());
        return response()->json($exercise);
    }

    public function destroy(Exercise $exercise)
    {
        $result = $this->exerciseService->deleteExercise($exercise, auth()->user());
        
        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
                'error' => $result['error'],
                'details' => $result['details'] ?? null
            ], $result['status_code']);
        }
        
        return response()->json([
            'message' => $result['message']
        ], $result['status_code']);
    }

    public function checkDependencies(Exercise $exercise)
    {
        $dependencies = $this->exerciseService->checkExerciseDependencies($exercise);
        return response()->json($dependencies);
    }

    public function forceDestroy(Exercise $exercise)
    {
        $result = $this->exerciseService->forceDeleteExercise($exercise, auth()->user());
        
        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
                'error' => $result['error'],
                'details' => $result['details'] ?? null
            ], $result['status_code']);
        }
        
        $response = ['message' => $result['message']];
        if (isset($result['warning'])) {
            $response['warning'] = $result['warning'];
        }
        
        return response()->json($response, $result['status_code']);
    }

    public function bulkDelete(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:gym_exercises,id',
        ]);

        $deleted = 0;
        $errors = [];

        foreach ($data['ids'] as $id) {
            $exercise = Exercise::find($id);
            if (!$exercise) continue;

            $result = $this->exerciseService->deleteExercise($exercise, $request->user());
            if ($result['success']) {
                $deleted++;
            } else {
                $errors[] = ['id' => $id, 'message' => $result['message']];
            }
        }

        return response()->json([
            'deleted_count' => $deleted,
            'errors' => $errors,
        ]);
    }

    public function duplicate(Exercise $exercise)
    {
        $duplicated = $this->exerciseService->duplicateExercise($exercise, auth()->user());
        return response()->json($duplicated, 201);
    }
}
