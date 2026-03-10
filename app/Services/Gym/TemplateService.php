<?php

namespace App\Services\Gym;

use App\Models\Gym\DailyTemplate;
use App\Models\Gym\WeeklyTemplate;
use App\Models\Gym\Exercise;
use App\Services\Core\AuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TemplateService
{
    public function __construct(
        private AuditService $auditService
    ) {}

    // ==================== PLANTILLAS DIARIAS ====================

    /**
     * Obtener plantillas diarias con filtros
     */
    public function getFilteredDailyTemplates(array $filters, int $perPage = 20, array $includes = []): LengthAwarePaginator
    {
        // Sin cache: siempre consultar DB para garantizar datos frescos
        return $this->buildDailyTemplatesQuery($filters, $perPage, $includes);
    }

    /**
     * Construir query de plantillas diarias
     */
    private function buildDailyTemplatesQuery(array $filters, int $perPage, array $includes): LengthAwarePaginator
    {
        $query = DailyTemplate::query();

        // Aplicar todos los filtros
        $this->applyDailyTemplateFilters($query, $filters);

        // Cargar relaciones si se especifican
        if (!empty($includes)) {
            $query->with($includes);
        }

        // Aplicar ordenamiento dinámico
        $this->applyDynamicSorting($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Determinar si se debe usar cache
     */
    private function shouldUseCache(array $filters): bool
    {
        // No usar cache si hay filtros específicos de búsqueda
        if (!empty($filters['search']) || !empty($filters['q'])) {
            return false;
        }

        // No usar cache si hay filtros complejos
        $complexFilters = ['target_muscle_groups', 'equipment_needed', 'tags'];
        foreach ($complexFilters as $filter) {
            if (!empty($filters[$filter])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generar clave de cache — incluye versión para permitir invalidación
     * sin depender de Redis ni pattern matching
     */
    private function generateCacheKey(string $type, array $filters, int $perPage, array $includes): string
    {
        $version = Cache::get('templates_cache_version', 0);

        $keyData = [
            'type' => $type,
            'filters' => array_filter($filters),
            'per_page' => $perPage,
            'includes' => $includes,
        ];

        return 'templates_v' . $version . '_' . md5(serialize($keyData));
    }

    /**
     * Aplicar filtros a la query de plantillas diarias
     */
    private function applyDailyTemplateFilters($query, array $filters): void
    {
        // Filtro por búsqueda (múltiples campos)
        if (!empty($filters['search']) || !empty($filters['q'])) {
            $search = $filters['search'] ?: $filters['q'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereJsonContains('tags', $search);
            });
        }

        // Filtros de objetivo/goal (compatibilidad con ambos nombres)
        if (!empty($filters['primary_goal']) || !empty($filters['goal'])) {
            $goal = $filters['primary_goal'] ?: $filters['goal'];
            $query->where('goal', $goal);
        }

        // Filtros de dificultad/nivel (compatibilidad con ambos nombres)
        if (!empty($filters['difficulty']) || !empty($filters['level'])) {
            $level = $filters['difficulty'] ?: $filters['level'];
            $query->where('level', $level);
        }

        // Filtro por grupos musculares
        if (!empty($filters['target_muscle_groups'])) {
            $muscleGroups = is_array($filters['target_muscle_groups']) 
                ? $filters['target_muscle_groups'] 
                : explode(',', $filters['target_muscle_groups']);
            
            foreach ($muscleGroups as $group) {
                $group = trim($group);
                if (!empty($group)) {
                    $query->whereJsonContains('tags', $group);
                }
            }
        }

        // Filtro por equipamiento
        if (!empty($filters['equipment_needed'])) {
            $equipment = is_array($filters['equipment_needed']) 
                ? $filters['equipment_needed'] 
                : explode(',', $filters['equipment_needed']);
            
            foreach ($equipment as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $query->whereJsonContains('tags', $item);
                }
            }
        }

        // Filtro por tags
        if (!empty($filters['tags'])) {
            $tags = is_array($filters['tags']) 
                ? $filters['tags'] 
                : explode(',', $filters['tags']);
            
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if (!empty($tag)) {
                    $query->whereJsonContains('tags', $tag);
                }
            }
        }

        // Filtro por preset
        if (isset($filters['is_preset'])) {
            $query->where('is_preset', $filters['is_preset']);
        }

        // Filtro por duración
        if (!empty($filters['duration_min'])) {
            $query->where('estimated_duration_min', '>=', $filters['duration_min']);
        }
        if (!empty($filters['duration_max'])) {
            $query->where('estimated_duration_min', '<=', $filters['duration_max']);
        }
    }

    /**
     * Aplicar ordenamiento dinámico
     */
    private function applyDynamicSorting($query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        // Validar campos de ordenamiento permitidos
        $allowedSortFields = [
            'created_at', 'updated_at', 'title', 'goal', 'level', 
            'estimated_duration_min', 'is_preset'
        ];

        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        // Aplicar ordenamiento
        if ($sortBy === 'created_at' && $sortDirection === 'desc') {
            // Orden por defecto: presets primero, luego por fecha
            $query->orderByDesc('is_preset')->orderByDesc('created_at')->orderBy('title');
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }
    }

    /**
     * Crear plantilla diaria
     */
    public function createDailyTemplate(array $data, $user): DailyTemplate
    {
        return DB::transaction(function () use ($data, $user) {
            // Crear plantilla
            $template = DailyTemplate::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'difficulty_level' => $data['difficulty_level'],
                'estimated_duration' => $data['estimated_duration'],
                'target_muscle_groups' => $data['target_muscle_groups'],
                'equipment_needed' => $data['equipment_needed'] ?? [],
                'is_preset' => $data['is_preset'] ?? false,
                'is_public' => $data['is_public'] ?? false,
                'tags' => $data['tags'] ?? [],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // Agregar ejercicios
            if (!empty($data['exercises'])) {
                $this->addExercisesToDailyTemplate($template, $data['exercises']);
            }

            // Auditoría
            $this->auditService->log(
                user: $user,
                action: 'create_template',
                resourceType: 'daily_template',
                resourceId: $template->id,
                details: [
                    'template_title' => $template->title,
                    'category' => $template->category,
                    'exercises_count' => count($data['exercises'] ?? []),
                ],
                severity: 'low',
                category: 'gym'
            );

            $this->clearTemplateCache();
            return $template->load(['exercises.sets', 'exercises.exercise']);
        });
    }

    /**
     * Actualizar plantilla diaria
     */
    public function updateDailyTemplate(DailyTemplate $template, array $data, $user): DailyTemplate
    {
        return DB::transaction(function () use ($template, $data, $user) {
            $oldValues = $template->toArray();

            // Actualizar plantilla
            $template->update([
                'title' => $data['title'] ?? $template->title,
                'description' => $data['description'] ?? $template->description,
                'category' => $data['category'] ?? $template->category,
                'difficulty_level' => $data['difficulty_level'] ?? $template->difficulty_level,
                'estimated_duration' => $data['estimated_duration'] ?? $template->estimated_duration,
                'target_muscle_groups' => $data['target_muscle_groups'] ?? $template->target_muscle_groups,
                'equipment_needed' => $data['equipment_needed'] ?? $template->equipment_needed,
                'is_preset' => $data['is_preset'] ?? $template->is_preset,
                'is_public' => $data['is_public'] ?? $template->is_public,
                'tags' => $data['tags'] ?? $template->tags,
                'notes' => $data['notes'] ?? $template->notes,
            ]);

            // Actualizar ejercicios si se proporcionan
            if (isset($data['exercises'])) {
                // Eliminar ejercicios existentes
                $template->exercises()->delete();
                // Agregar nuevos ejercicios
                $this->addExercisesToDailyTemplate($template, $data['exercises']);
            }

            // Auditoría
            $this->auditService->log(
                user: $user,
                action: 'update',
                resourceType: 'daily_template',
                resourceId: $template->id,
                details: [
                    'template_title' => $template->title,
                    'changes' => array_keys($data),
                ],
                oldValues: $oldValues,
                newValues: $template->fresh()->toArray(),
                severity: 'low',
                category: 'gym'
            );

            $this->clearTemplateCache();
            return $template->load(['exercises.sets', 'exercises.exercise']);
        });
    }

    /**
     * Duplicar plantilla diaria
     */
    public function duplicateDailyTemplate(DailyTemplate $template, $user): DailyTemplate
    {
        return DB::transaction(function () use ($template, $user) {
            // Duplicar plantilla
            $duplicated = $template->replicate();
            $duplicated->title = $template->title . ' (Copia)';
            $duplicated->is_preset = false;
            $duplicated->created_by = $user->id;
            $duplicated->save();

            // Duplicar ejercicios y sets
            foreach ($template->exercises as $templateExercise) {
                $newTemplateExercise = $templateExercise->replicate();
                $newTemplateExercise->daily_template_id = $duplicated->id;
                $newTemplateExercise->save();

                foreach ($templateExercise->sets as $set) {
                    $newSet = $set->replicate();
                    $newSet->template_exercise_id = $newTemplateExercise->id;
                    $newSet->save();
                }
            }

            // Auditoría
            $this->auditService->log(
                user: $user,
                action: 'duplicate',
                resourceType: 'daily_template',
                resourceId: $duplicated->id,
                details: [
                    'original_template' => $template->title,
                    'new_template' => $duplicated->title,
                    'original_id' => $template->id,
                ],
                severity: 'low',
                category: 'gym'
            );

            return $duplicated->load(['exercises.sets', 'exercises.exercise']);
        });
    }

    // ==================== PLANTILLAS SEMANALES ====================

    /**
     * Obtener plantillas semanales con filtros
     */
    public function getFilteredWeeklyTemplates(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = WeeklyTemplate::query();

        // Filtro por búsqueda
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereJsonContains('tags', $search);
            });
        }

        // Filtros específicos
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['difficulty_level'])) {
            $query->where('difficulty_level', $filters['difficulty_level']);
        }

        if (!empty($filters['target_goals'])) {
            $goals = is_array($filters['target_goals']) 
                ? $filters['target_goals'] 
                : [$filters['target_goals']];
            
            foreach ($goals as $goal) {
                $query->whereJsonContains('target_goals', $goal);
            }
        }

        if (isset($filters['is_preset'])) {
            $query->where('is_preset', $filters['is_preset']);
        }

        // Ordenamiento
        $query->orderByDesc('is_preset')->orderBy('title');

        return $query->paginate($perPage);
    }

    /**
     * Crear plantilla semanal
     */
    public function createWeeklyTemplate(array $data, $user): WeeklyTemplate
    {
        return DB::transaction(function () use ($data, $user) {
            // Crear plantilla
            $template = WeeklyTemplate::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'difficulty_level' => $data['difficulty_level'],
                'weeks_duration' => $data['weeks_duration'],
                'sessions_per_week' => $data['sessions_per_week'],
                'target_goals' => $data['target_goals'],
                'target_muscle_groups' => $data['target_muscle_groups'],
                'equipment_needed' => $data['equipment_needed'] ?? [],
                'is_preset' => $data['is_preset'] ?? false,
                'is_public' => $data['is_public'] ?? false,
                'tags' => $data['tags'] ?? [],
                'notes' => $data['notes'] ?? null,
                'progression' => $data['progression'] ?? null,
                'created_by' => $user->id,
            ]);

            // Agregar días
            if (!empty($data['days'])) {
                $this->addDaysToWeeklyTemplate($template, $data['days']);
            }

            // Auditoría
            $this->auditService->log(
                user: $user,
                action: 'create_template',
                resourceType: 'weekly_template',
                resourceId: $template->id,
                details: [
                    'template_title' => $template->title,
                    'category' => $template->category,
                    'sessions_per_week' => $template->sessions_per_week,
                ],
                severity: 'low',
                category: 'gym'
            );

            $this->clearTemplateCache();
            return $template->load('days');
        });
    }

    /**
     * Obtener estadísticas de plantillas
     */
    public function getTemplateStats(): array
    {
        return Cache::remember('template_stats', 300, function () {
            return [
                'daily_templates' => [
                    'total' => DailyTemplate::count(),
                    'presets' => DailyTemplate::where('is_preset', true)->count(),
                    'public' => DailyTemplate::where('is_public', true)->count(),
                    'by_category' => DailyTemplate::select('category', DB::raw('count(*) as count'))
                        ->groupBy('category')
                        ->pluck('count', 'category')
                        ->toArray(),
                ],
                'weekly_templates' => [
                    'total' => WeeklyTemplate::count(),
                    'presets' => WeeklyTemplate::where('is_preset', true)->count(),
                    'public' => WeeklyTemplate::where('is_public', true)->count(),
                    'by_category' => WeeklyTemplate::select('category', DB::raw('count(*) as count'))
                        ->groupBy('category')
                        ->pluck('count', 'category')
                        ->toArray(),
                ],
                'most_used_daily' => $this->getMostUsedDailyTemplates(5),
                'most_used_weekly' => $this->getMostUsedWeeklyTemplates(5),
            ];
        });
    }

    /**
     * Obtener plantillas diarias más utilizadas
     */
    public function getMostUsedDailyTemplates(int $limit = 10): Collection
    {
        return Cache::remember("most_used_daily_templates_{$limit}", 600, function () use ($limit) {
            return DailyTemplate::select('daily_templates.*', DB::raw('COUNT(daily_assignments.id) as usage_count'))
                ->leftJoin('daily_assignments', 'daily_templates.id', '=', 'daily_assignments.daily_template_id')
                ->groupBy('daily_templates.id')
                ->orderByDesc('usage_count')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Obtener plantillas semanales más utilizadas
     */
    public function getMostUsedWeeklyTemplates(int $limit = 10): Collection
    {
        return Cache::remember("most_used_weekly_templates_{$limit}", 600, function () use ($limit) {
            return WeeklyTemplate::select('weekly_templates.*', DB::raw('COUNT(weekly_assignments.id) as usage_count'))
                ->leftJoin('weekly_assignments', 'weekly_templates.id', '=', 'weekly_assignments.source_id')
                ->where('weekly_assignments.source_type', 'template')
                ->groupBy('weekly_templates.id')
                ->orderByDesc('usage_count')
                ->limit($limit)
                ->get();
        });
    }

    // ==================== MÉTODOS PRIVADOS ====================

    /**
     * Agregar ejercicios a plantilla diaria
     */
    private function addExercisesToDailyTemplate(DailyTemplate $template, array $exercises): void
    {
        foreach ($exercises as $exerciseData) {
            $templateExercise = $template->exercises()->create([
                'exercise_id' => $exerciseData['exercise_id'],
                'order' => $exerciseData['order'],
                'rest_seconds' => $exerciseData['rest_seconds'] ?? null,
                'notes' => $exerciseData['notes'] ?? null,
            ]);

            // Agregar sets
            if (!empty($exerciseData['sets'])) {
                foreach ($exerciseData['sets'] as $setData) {
                    $templateExercise->sets()->create([
                        'set_number' => $setData['set_number'],
                        'reps' => $setData['reps'] ?? null,
                        'weight' => $setData['weight'] ?? null,
                        'duration_seconds' => $setData['duration_seconds'] ?? null,
                        'distance_meters' => $setData['distance_meters'] ?? null,
                        'rest_seconds' => $setData['rest_seconds'] ?? null,
                        'notes' => $setData['notes'] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * Agregar días a plantilla semanal
     */
    private function addDaysToWeeklyTemplate(WeeklyTemplate $template, array $days): void
    {
        foreach ($days as $dayData) {
            $template->days()->create([
                'day_of_week' => $dayData['day_of_week'],
                'daily_template_id' => $dayData['daily_template_id'],
                'is_rest_day' => $dayData['is_rest_day'] ?? false,
                'notes' => $dayData['notes'] ?? null,
                'order' => $dayData['order'] ?? null,
            ]);
        }
    }

    /**
     * Limpiar cache relacionado con plantillas.
     * Usa un número de versión para invalidar todas las claves de lista
     * sin depender de Redis ni pattern matching.
     */
    public function clearTemplateCache(): void
    {
        // Incrementar versión invalida todas las claves de lista generadas
        // por generateCacheKey() sin necesidad de conocerlas individualmente
        Cache::increment('templates_cache_version');

        Cache::forget('template_stats');

        for ($i = 1; $i <= 20; $i++) {
            Cache::forget("most_used_daily_templates_{$i}");
            Cache::forget("most_used_weekly_templates_{$i}");
        }
    }

    /**
     * Obtener plantillas populares (con cache optimizado)
     */
    public function getPopularDailyTemplates(int $limit = 10): Collection
    {
        return Cache::remember("popular_daily_templates_{$limit}", 1800, function () use ($limit) {
            return DailyTemplate::where('is_preset', true)
                ->with(['exercises.exercise'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Obtener plantillas recientes (con cache)
     */
    public function getRecentDailyTemplates(int $limit = 5): Collection
    {
        return Cache::remember("recent_daily_templates_{$limit}", 600, function () use ($limit) {
            return DailyTemplate::with(['exercises.exercise'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        });
    }
}
