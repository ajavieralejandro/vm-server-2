<?php

namespace App\Http\Controllers\Gym\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gym\ExerciseRequest;
use App\Http\Resources\Gym\ExerciseResource;
use App\Models\Gym\Exercise;
use App\Services\Gym\ExerciseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function __construct(
        private ExerciseService $exerciseService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'exercise_type', 'category', 'is_active',
            'muscle_groups', 'target_muscle_groups',
            'equipment', 'difficulty_level', 'movement_pattern',
            'tags', 'sort_by', 'sort_direction',
        ]);

        $perPage = min($request->integer('per_page', 20), 100);
        $exercises = $this->exerciseService->getFilteredExercises($filters, $perPage);

        return response()->json([
            'ok' => true,
            'data' => ExerciseResource::collection($exercises->items()),
            'meta' => [
                'current_page' => $exercises->currentPage(),
                'per_page' => $exercises->perPage(),
                'total' => $exercises->total(),
                'last_page' => $exercises->lastPage(),
            ],
        ]);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $this->exerciseService->getMeta(),
        ]);
    }

    public function store(ExerciseRequest $request): JsonResponse
    {
        $exercise = $this->exerciseService->createExercise($request->validated(), $request->user());

        return response()->json([
            'ok' => true,
            'data' => new ExerciseResource($exercise),
            'message' => 'Ejercicio creado correctamente.',
        ], 201);
    }

    public function show(Exercise $exercise): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => new ExerciseResource($exercise),
        ]);
    }

    public function update(ExerciseRequest $request, Exercise $exercise): JsonResponse
    {
        $updated = $this->exerciseService->updateExercise($exercise, $request->validated(), $request->user());

        return response()->json([
            'ok' => true,
            'data' => new ExerciseResource($updated),
            'message' => 'Ejercicio actualizado correctamente.',
        ]);
    }

    public function destroy(Exercise $exercise): JsonResponse
    {
        $result = $this->exerciseService->deleteExercise($exercise, auth()->user());

        if (! $result['success']) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'],
                'error' => $result['error'],
                'details' => $result['details'] ?? null,
            ], $result['status_code']);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
        ], $result['status_code']);
    }

    public function checkDependencies(Exercise $exercise): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $this->exerciseService->checkExerciseDependencies($exercise),
        ]);
    }

    public function forceDestroy(Exercise $exercise): JsonResponse
    {
        $result = $this->exerciseService->forceDeleteExercise($exercise, auth()->user());

        if (! $result['success']) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'],
                'error' => $result['error'],
                'details' => $result['details'] ?? null,
            ], $result['status_code']);
        }

        $response = [
            'ok' => true,
            'message' => $result['message'],
        ];

        if (isset($result['warning'])) {
            $response['warning'] = $result['warning'];
        }

        return response()->json($response, $result['status_code']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:gym_exercises,id',
        ]);

        $deleted = 0;
        $errors = [];

        foreach ($data['ids'] as $id) {
            $exercise = Exercise::find($id);
            if (! $exercise) {
                continue;
            }

            $result = $this->exerciseService->deleteExercise($exercise, $request->user());
            if ($result['success']) {
                $deleted++;
            } else {
                $errors[] = ['id' => $id, 'message' => $result['message']];
            }
        }

        return response()->json([
            'ok' => true,
            'deleted_count' => $deleted,
            'errors' => $errors,
        ]);
    }

    public function duplicate(Exercise $exercise): JsonResponse
    {
        $duplicated = $this->exerciseService->duplicateExercise($exercise, auth()->user());

        return response()->json([
            'ok' => true,
            'data' => new ExerciseResource($duplicated),
            'message' => 'Ejercicio duplicado correctamente.',
        ], 201);
    }
}
