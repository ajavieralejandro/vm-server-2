<?php

namespace App\Http\Controllers\Gym\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gym\DailyTemplate;
use App\Models\Gym\DailyTemplateExercise;
use App\Models\Gym\DailyTemplateSet;
use App\Services\Gym\TemplateService;
use App\Services\Gym\SetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyTemplateController extends Controller
{
    public function __construct(
        private TemplateService $templateService,
        private SetService $setService
    ) {}
    public function index(Request $request)
    {
        // Construir filtros desde los parámetros del frontend
        $filters = $this->buildFilters($request);
        
        // Determinar qué relaciones cargar
        $includes = $this->parseIncludes($request);
        
        // Obtener paginación
        $perPage = $request->integer('per_page', 20);
        
        // Usar TemplateService para obtener plantillas filtradas
        $templates = $this->templateService->getFilteredDailyTemplates($filters, $perPage, $includes);
        
        return response()->json($templates);
    }

    /**
     * Construir array de filtros desde la request
     */
    private function buildFilters(Request $request): array
    {
        return [
            'search' => $request->string('search')->toString(),
            'q' => $request->string('q')->toString(), // Compatibilidad con filtro existente
            'difficulty' => $request->string('difficulty')->toString(),
            'level' => $request->string('level')->toString(), // Compatibilidad
            'primary_goal' => $request->string('primary_goal')->toString(),
            'goal' => $request->string('goal')->toString(), // Compatibilidad
            'target_muscle_groups' => $request->string('target_muscle_groups')->toString(),
            'equipment_needed' => $request->string('equipment_needed')->toString(),
            'tags' => $request->string('tags')->toString(),
            'intensity_level' => $request->string('intensity_level')->toString(),
            'sort_by' => $request->string('sort_by', 'created_at')->toString(),
            'sort_direction' => $request->string('sort_direction', 'desc')->toString(),
            'is_preset' => $request->has('is_preset') ? $request->boolean('is_preset') : null,
        ];
    }

    /**
     * Determinar qué relaciones incluir basado en parámetros
     */
    private function parseIncludes(Request $request): array
    {
        $includes = [];
        
        // Verificar parámetros específicos del frontend
        if ($request->boolean('with_exercises') || 
            $request->has('include') && str_contains($request->string('include'), 'exercises')) {
            $includes[] = 'exercises';
        }
        
        if ($request->boolean('with_sets') || 
            $request->has('include') && str_contains($request->string('include'), 'exercises.sets')) {
            $includes[] = 'exercises.sets';
        }
        
        if ($request->has('include') && str_contains($request->string('include'), 'exercises.exercise')) {
            $includes[] = 'exercises.exercise';
        }
        
        // Si no se especifica nada, cargar relaciones básicas para compatibilidad
        if (empty($includes) && ($request->boolean('with_exercises') || $request->boolean('with_sets'))) {
            $includes = ['exercises', 'exercises.exercise', 'exercises.sets'];
        }
        
        return $includes;
    }

    public function show(DailyTemplate $dailyTemplate)
    {
        $dailyTemplate->load(['exercises.sets', 'exercises.exercise']);
        return response()->json($dailyTemplate);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'goal' => 'nullable|string|max:50',
            'estimated_duration_min' => 'nullable|integer|min:0|max:600',
            'level' => 'nullable|string|max:50',
            'tags' => 'array',
            'tags.*' => 'string',
            'exercises' => 'array',
            'exercises.*.exercise_id' => 'nullable|integer|exists:gym_exercises,id',
            'exercises.*.order' => 'nullable|integer|min:1',
            'exercises.*.display_order' => 'nullable|integer|min:1',
            'exercises.*.notes' => 'nullable|string',
            'exercises.*.sets' => 'array',
            'exercises.*.sets.*.set_number' => 'nullable|integer|min:1',
            'exercises.*.sets.*.reps_min' => 'nullable|integer|min:1',
            'exercises.*.sets.*.reps_max' => 'nullable|integer|min:1',
            'exercises.*.sets.*.weight_min' => 'nullable|numeric|min:0|max:1000',
            'exercises.*.sets.*.weight_max' => 'nullable|numeric|min:0|max:1000',
            'exercises.*.sets.*.weight_target' => 'nullable|numeric|min:0|max:1000',
            'exercises.*.sets.*.rest_seconds' => 'nullable|integer|min:0',
            'exercises.*.sets.*.rpe_target' => 'nullable|numeric|min:0|max:10',
            'exercises.*.sets.*.notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $tpl = DailyTemplate::create([
                'created_by' => $request->user()->id ?? null,
                'title' => $data['title'],
                'goal' => $data['goal'] ?? null,
                'estimated_duration_min' => $data['estimated_duration_min'] ?? null,
                'level' => $data['level'] ?? null,
                'tags' => $data['tags'] ?? [],
                'is_preset' => false,
            ]);

            foreach (($data['exercises'] ?? []) as $i => $ex) {
                $dte = DailyTemplateExercise::create([
                    'daily_template_id' => $tpl->id,
                    'exercise_id' => $ex['exercise_id'] ?? null,
                    'display_order' => $ex['display_order'] ?? $ex['order'] ?? ($i + 1),
                    'notes' => $ex['notes'] ?? null,
                ]);
                
                // Usar SetService para crear los sets
                if (!empty($ex['sets'])) {
                    $this->setService->createSetsForExercise($dte, $ex['sets']);
                }
            }

            $tpl->load(['exercises.sets', 'exercises.exercise']);
            $this->templateService->clearTemplateCache();
            return response()->json($tpl, 201);
        });
    }

    public function update(Request $request, DailyTemplate $dailyTemplate)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'goal' => 'nullable|string|max:50',
            'estimated_duration_min' => 'nullable|integer|min:0|max:600',
            'level' => 'nullable|string|max:50',
            'tags' => 'array',
            'tags.*' => 'string',
            'exercises' => 'array', // si viene, reemplaza ejercicios completos
            'exercises.*.exercise_id' => 'nullable|integer|exists:gym_exercises,id',
            'exercises.*.order' => 'nullable|integer|min:1',
            'exercises.*.display_order' => 'nullable|integer|min:1',
            'exercises.*.notes' => 'nullable|string',
            'exercises.*.sets' => 'array',
            'exercises.*.sets.*.set_number' => 'nullable|integer|min:1',
            'exercises.*.sets.*.reps_min' => 'nullable|integer|min:1',
            'exercises.*.sets.*.reps_max' => 'nullable|integer|min:1',
            'exercises.*.sets.*.weight_min' => 'nullable|numeric|min:0|max:1000',
            'exercises.*.sets.*.weight_max' => 'nullable|numeric|min:0|max:1000',
            'exercises.*.sets.*.weight_target' => 'nullable|numeric|min:0|max:1000',
            'exercises.*.sets.*.rest_seconds' => 'nullable|integer|min:0',
            'exercises.*.sets.*.rpe_target' => 'nullable|numeric|min:0|max:10',
            'exercises.*.sets.*.notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $dailyTemplate) {
            $dailyTemplate->update([
                'title' => $data['title'] ?? $dailyTemplate->title,
                'goal' => $data['goal'] ?? $dailyTemplate->goal,
                'estimated_duration_min' => $data['estimated_duration_min'] ?? $dailyTemplate->estimated_duration_min,
                'level' => $data['level'] ?? $dailyTemplate->level,
                'tags' => $data['tags'] ?? $dailyTemplate->tags,
            ]);

            if (array_key_exists('exercises', $data)) {
                // Eliminar ejercicios anteriores (cascade eliminará sets)
                $dailyTemplate->exercises()->delete();
                
                // Crear nuevos ejercicios y sets
                foreach ($data['exercises'] as $i => $ex) {
                    $dte = DailyTemplateExercise::create([
                        'daily_template_id' => $dailyTemplate->id,
                        'exercise_id' => $ex['exercise_id'] ?? null,
                        'display_order' => $ex['display_order'] ?? $ex['order'] ?? ($i + 1),
                        'notes' => $ex['notes'] ?? null,
                    ]);
                    
                    // Usar SetService para crear los sets
                    if (!empty($ex['sets'])) {
                        $this->setService->createSetsForExercise($dte, $ex['sets']);
                    }
                }
            }

            $dailyTemplate->load(['exercises.sets', 'exercises.exercise']);
            $this->templateService->clearTemplateCache();
            return response()->json($dailyTemplate);
        });
    }

    public function destroy(DailyTemplate $dailyTemplate)
    {
        $dailyTemplate->delete();
        $this->templateService->clearTemplateCache();
        return response()->noContent();
    }

    public function duplicate(DailyTemplate $dailyTemplate)
    {
        return DB::transaction(function () use ($dailyTemplate) {
            // Duplicar plantilla
            $duplicated = $dailyTemplate->replicate();
            $duplicated->title = $dailyTemplate->title . ' (Copia)';
            $duplicated->is_preset = false;
            $duplicated->created_by = auth()->id();
            $duplicated->save();
            
            // Duplicar ejercicios y sets
            foreach ($dailyTemplate->exercises as $templateExercise) {
                $newTemplateExercise = $templateExercise->replicate();
                $newTemplateExercise->daily_template_id = $duplicated->id;
                $newTemplateExercise->save();
                
                // Usar SetService para duplicar sets
                if ($templateExercise->sets->isNotEmpty()) {
                    $this->setService->duplicateSets($templateExercise, $newTemplateExercise);
                }
            }
            
            return response()->json($duplicated->load(['exercises.sets', 'exercises.exercise']), 201);
        });
    }
}
