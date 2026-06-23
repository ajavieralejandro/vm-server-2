<?php

namespace App\Services\Gym;

use App\Models\Gym\Exercise;
use App\Support\Gym\ExerciseDomainConfig;
use App\Services\Core\AuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExerciseService
{
    public function __construct(
        private AuditService $auditService
    ) {}

    public function getFilteredExercises(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Exercise::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('instructions', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search);
            });
        }

        if (! empty($filters['exercise_type'])) {
            $types = is_array($filters['exercise_type'])
                ? $filters['exercise_type']
                : [$filters['exercise_type']];
            $query->whereIn('exercise_type', $types);
        }

        if (! empty($filters['category'])) {
            $categories = is_array($filters['category'])
                ? $filters['category']
                : [$filters['category']];
            $query->whereIn('category', $categories);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['muscle_groups'])) {
            $muscleGroups = is_array($filters['muscle_groups'])
                ? $filters['muscle_groups']
                : [$filters['muscle_groups']];

            foreach ($muscleGroups as $group) {
                $query->whereJsonContains('muscle_groups', $group);
            }
        }

        if (! empty($filters['target_muscle_groups'])) {
            $targetMuscleGroups = is_array($filters['target_muscle_groups'])
                ? $filters['target_muscle_groups']
                : [$filters['target_muscle_groups']];

            foreach ($targetMuscleGroups as $group) {
                $query->whereJsonContains('target_muscle_groups', $group);
            }
        }

        if (! empty($filters['difficulty_level'])) {
            if (is_array($filters['difficulty_level'])) {
                $query->whereIn('difficulty_level', $filters['difficulty_level']);
            } else {
                $query->where('difficulty_level', $filters['difficulty_level']);
            }
        }

        if (! empty($filters['equipment'])) {
            $query->where('equipment', 'like', "%{$filters['equipment']}%");
        }

        if (! empty($filters['movement_pattern'])) {
            $query->where('movement_pattern', 'like', "%{$filters['movement_pattern']}%");
        }

        if (! empty($filters['tags'])) {
            $tags = is_array($filters['tags']) ? $filters['tags'] : [$filters['tags']];
            foreach ($tags as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
        }

        $sortBy = $filters['sort_by'] ?? 'name';
        $sortDirection = $filters['sort_direction'] ?? 'asc';

        $allowedSorts = [
            'name',
            'exercise_type',
            'category',
            'difficulty_level',
            'movement_pattern',
            'created_at',
            'updated_at',
        ];

        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        return $query->paginate($perPage);
    }

    public function createExercise(array $data, $user): Exercise
    {
        return DB::transaction(function () use ($data, $user) {
            $exercise = Exercise::create($this->buildExercisePayload($data, $user));

            $this->auditService->log(
                action: 'create',
                resourceType: 'exercise',
                resourceId: $exercise->id,
                details: [
                    'exercise_name' => $exercise->name,
                    'exercise_type' => $exercise->exercise_type,
                    'category' => $exercise->category,
                ],
                severity: 'low',
                category: 'gym'
            );

            $this->clearExerciseCache();

            return $exercise->fresh();
        });
    }

    public function updateExercise(Exercise $exercise, array $data, $user): Exercise
    {
        return DB::transaction(function () use ($exercise, $data, $user) {
            $oldValues = $exercise->toArray();

            $exercise->update($this->buildExercisePayload($data, $user, $exercise));

            $this->auditService->log(
                action: 'update',
                resourceType: 'exercise',
                resourceId: $exercise->id,
                details: [
                    'exercise_name' => $exercise->name,
                    'changes' => array_keys($data),
                ],
                oldValues: $oldValues,
                newValues: $exercise->fresh()->toArray(),
                severity: 'low',
                category: 'gym'
            );

            $this->clearExerciseCache();

            return $exercise->fresh();
        });
    }

    public function checkExerciseDependencies(Exercise $exercise): array
    {
        $dependencies = [
            'daily_templates' => $exercise->dailyTemplateExercises()->count(),
        ];

        $canDelete = array_sum($dependencies) === 0;

        return [
            'can_delete' => $canDelete,
            'dependencies' => $dependencies,
            'total_references' => array_sum($dependencies),
            'exercise' => [
                'id' => $exercise->id,
                'name' => $exercise->name,
            ],
        ];
    }

    public function deleteExercise(Exercise $exercise, $user): array
    {
        $dependencies = $this->checkExerciseDependencies($exercise);

        if (! $dependencies['can_delete']) {
            return [
                'success' => false,
                'error' => 'EXERCISE_IN_USE',
                'message' => 'No se puede eliminar el ejercicio porque está siendo usado en plantillas de entrenamiento.',
                'details' => [
                    'templates_count' => $dependencies['dependencies']['daily_templates'],
                    'exercise_id' => $exercise->id,
                    'exercise_name' => $exercise->name,
                ],
                'status_code' => 422,
            ];
        }

        return DB::transaction(function () use ($exercise) {
            try {
                $this->auditService->log(
                    action: 'delete',
                    resourceType: 'exercise',
                    resourceId: $exercise->id,
                    details: [
                        'exercise_name' => $exercise->name,
                        'reason' => 'Exercise deleted - no dependencies found',
                    ],
                    severity: 'medium',
                    category: 'gym'
                );

                $exercise->delete();
                $this->clearExerciseCache();

                return [
                    'success' => true,
                    'message' => 'Ejercicio eliminado correctamente',
                    'status_code' => 200,
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'DELETE_FAILED',
                    'message' => 'Error al eliminar el ejercicio',
                    'details' => [
                        'exercise_id' => $exercise->id,
                        'exercise_name' => $exercise->name,
                    ],
                    'status_code' => 500,
                ];
            }
        });
    }

    public function forceDeleteExercise(Exercise $exercise, $user): array
    {
        if (! $user->is_admin) {
            return [
                'success' => false,
                'error' => 'INSUFFICIENT_PERMISSIONS',
                'message' => 'No tienes permisos para realizar eliminación forzada',
                'status_code' => 403,
            ];
        }

        return DB::transaction(function () use ($exercise) {
            try {
                $affectedTemplateIds = $exercise->dailyTemplateExercises()
                    ->pluck('daily_template_id')
                    ->unique()
                    ->toArray();

                $templatesCount = count($affectedTemplateIds);

                $this->auditService->log(
                    action: 'force_delete',
                    resourceType: 'exercise',
                    resourceId: $exercise->id,
                    details: [
                        'exercise_name' => $exercise->name,
                        'reason' => 'Force delete - admin override',
                        'templates_deleted' => $templatesCount,
                        'affected_template_ids' => $affectedTemplateIds,
                    ],
                    severity: 'high',
                    category: 'gym'
                );

                if ($templatesCount > 0) {
                    \App\Models\Gym\DailyTemplate::whereIn('id', $affectedTemplateIds)->delete();
                }

                $exercise->delete();
                $this->clearExerciseCache();

                return [
                    'success' => true,
                    'message' => "Ejercicio eliminado correctamente. Se eliminaron {$templatesCount} plantilla(s) y sus asignaciones.",
                    'warning' => $templatesCount > 0
                        ? "Esta acción eliminó {$templatesCount} plantilla(s) que usaban este ejercicio y las desasignó de todos los estudiantes."
                        : null,
                    'deleted_templates_count' => $templatesCount,
                    'status_code' => 200,
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'FORCE_DELETE_FAILED',
                    'message' => 'Error al realizar eliminación forzada',
                    'details' => [
                        'exercise_id' => $exercise->id,
                        'exercise_name' => $exercise->name,
                        'error_message' => $e->getMessage(),
                    ],
                    'status_code' => 500,
                ];
            }
        });
    }

    public function duplicateExercise(Exercise $exercise, $user): Exercise
    {
        return DB::transaction(function () use ($exercise, $user) {
            $duplicated = $exercise->replicate();
            $duplicated->name = $exercise->name.' (Copia)';
            $duplicated->created_by = $user->id;
            $duplicated->save();

            $this->auditService->log(
                action: 'duplicate',
                resourceType: 'exercise',
                resourceId: $duplicated->id,
                details: [
                    'original_exercise' => $exercise->name,
                    'new_exercise' => $duplicated->name,
                    'original_id' => $exercise->id,
                ],
                severity: 'low',
                category: 'gym'
            );

            $this->clearExerciseCache();

            return $duplicated->fresh();
        });
    }

    public function getMeta(): array
    {
        $meta = Cache::remember('exercise_meta_v2', 1800, fn () => $this->buildMeta());

        if (empty($meta['types']) || empty($meta['categories'])) {
            Cache::forget('exercise_meta');
            Cache::forget('exercise_meta_v2');
            $meta = $this->buildMeta();
            Cache::put('exercise_meta_v2', $meta, 1800);
        }

        return $meta;
    }

    private function buildMeta(): array
    {
        $types = collect(ExerciseDomainConfig::types())
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        $categories = collect(ExerciseDomainConfig::categories())
            ->map(function ($items, $type) {
                return collect($items)
                    ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all();
            })
            ->all();

        $configTags = collect(ExerciseDomainConfig::tags());
        $dbTags = Exercise::query()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $tags = $configTags
            ->merge($dbTags)
            ->unique()
            ->sortBy(fn ($tag) => Str::lower((string) $tag))
            ->values()
            ->map(fn ($tag) => [
                'value' => $tag,
                'label' => Str::title(str_replace('_', ' ', (string) $tag)),
            ])
            ->all();

        return [
            'types' => $types,
            'categories' => $categories,
            'tags' => $tags,
        ];
    }

    public function getExerciseStats(): array
    {
        return Cache::remember('exercise_stats', 300, function () {
            return [
                'total_exercises' => Exercise::count(),
                'active_exercises' => Exercise::where('is_active', true)->count(),
                'by_exercise_type' => Exercise::select('exercise_type', DB::raw('count(*) as count'))
                    ->where('is_active', true)
                    ->groupBy('exercise_type')
                    ->pluck('count', 'exercise_type')
                    ->toArray(),
                'by_category' => Exercise::select('category', DB::raw('count(*) as count'))
                    ->where('is_active', true)
                    ->groupBy('category')
                    ->pluck('count', 'category')
                    ->toArray(),
                'by_difficulty' => Exercise::select('difficulty_level', DB::raw('count(*) as count'))
                    ->where('is_active', true)
                    ->groupBy('difficulty_level')
                    ->pluck('count', 'difficulty_level')
                    ->toArray(),
                'most_used' => $this->getMostUsedExercises(5),
                'recent_additions' => Exercise::where('created_at', '>=', now()->subDays(30))->count(),
            ];
        });
    }

    public function getMostUsedExercises(int $limit = 10): Collection
    {
        return Cache::remember("most_used_exercises_{$limit}", 600, function () use ($limit) {
            return Exercise::select('gym_exercises.*', DB::raw('COUNT(gym_daily_template_exercises.id) as usage_count'))
                ->leftJoin('gym_daily_template_exercises', 'gym_exercises.id', '=', 'gym_daily_template_exercises.exercise_id')
                ->groupBy('gym_exercises.id')
                ->orderByDesc('usage_count')
                ->limit($limit)
                ->get();
        });
    }

    public function getFilterOptions(): array
    {
        return Cache::remember('exercise_filter_options', 1800, function () {
            $exercises = Exercise::where('is_active', true)->get();

            return [
                'exercise_types' => $exercises->pluck('exercise_type')->unique()->filter()->sort()->values()->toArray(),
                'categories' => $exercises->pluck('category')->unique()->filter()->sort()->values()->toArray(),
                'muscle_groups' => $exercises->pluck('muscle_groups')->flatten()->unique()->sort()->values()->toArray(),
                'equipment' => $exercises->pluck('equipment')->unique()->filter()->sort()->values()->toArray(),
                'difficulty_levels' => $exercises->pluck('difficulty_level')->unique()->filter()->sort()->values()->toArray(),
                'tags' => $exercises->pluck('tags')->flatten()->unique()->filter()->sort()->values()->toArray(),
            ];
        });
    }

    private function buildExercisePayload(array $data, $user, ?Exercise $existing = null): array
    {
        $payload = [
            'name' => $data['name'] ?? $existing?->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $existing?->description,
            'exercise_type' => $data['exercise_type'] ?? $existing?->exercise_type,
            'category' => $data['category'] ?? $existing?->category,
            'muscle_groups' => array_key_exists('muscle_groups', $data) ? $data['muscle_groups'] : $existing?->muscle_groups,
            'target_muscle_groups' => array_key_exists('target_muscle_groups', $data) ? $data['target_muscle_groups'] : $existing?->target_muscle_groups,
            'movement_pattern' => array_key_exists('movement_pattern', $data) ? $data['movement_pattern'] : $existing?->movement_pattern,
            'equipment' => array_key_exists('equipment', $data) ? $data['equipment'] : $existing?->equipment,
            'difficulty_level' => array_key_exists('difficulty_level', $data) ? $data['difficulty_level'] : $existing?->difficulty_level,
            'tags' => array_key_exists('tags', $data) ? ($data['tags'] ?? []) : ($existing?->tags ?? []),
            'instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $existing?->instructions,
            'video_url' => array_key_exists('video_url', $data) ? $data['video_url'] : $existing?->video_url,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($existing?->is_active ?? true),
        ];

        if (! $existing) {
            $payload['created_by'] = $user->id;
        }

        return $payload;
    }

    private function clearExerciseCache(): void
    {
        Cache::forget('exercise_stats');
        Cache::forget('exercise_filter_options');
        Cache::forget('exercise_meta');
        Cache::forget('exercise_meta_v2');

        for ($i = 1; $i <= 20; $i++) {
            Cache::forget("most_used_exercises_{$i}");
        }
    }
}
