<?php

namespace App\Http\Requests\Gym;

use App\Support\Gym\ExerciseDomainConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->is_professor || $user->isAdmin());
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $isUpdate ? 'sometimes' : 'required';
        $types = ExerciseDomainConfig::typeSlugs();

        return [
            'name' => [$required, 'string', 'min:3', 'max:255'],
            'exercise_type' => [$required, 'string', Rule::in($types)],
            'category' => [$required, 'string', 'max:100'],
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'video_url' => 'nullable|url|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'is_active' => 'nullable|boolean',
            'muscle_groups' => 'nullable|array',
            'muscle_groups.*' => 'string|max:100',
            'target_muscle_groups' => 'nullable|array',
            'target_muscle_groups.*' => 'string|max:100',
            'movement_pattern' => 'nullable|string|max:255',
            'equipment' => 'nullable|string|max:255',
            'difficulty_level' => 'nullable|string|in:beginner,intermediate,advanced',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('exercise_type') ?? $this->route('exercise')?->exercise_type;
            $category = $this->input('category') ?? $this->route('exercise')?->category;

            if (! $type || ! $category) {
                return;
            }

            $allowedCategories = ExerciseDomainConfig::categorySlugsForType($type);

            if (app()->environment('local')) {
                logger()->debug('Exercise validation domain', [
                    'types' => ExerciseDomainConfig::typeSlugs(),
                    'type' => $type,
                    'category' => $category,
                    'allowed_categories' => $allowedCategories,
                ]);
            }

            if (! in_array($category, $allowedCategories, true)) {
                $validator->errors()->add(
                    'category',
                    'La categoría seleccionada no corresponde al tipo de ejercicio indicado.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del ejercicio es obligatorio.',
            'name.min' => 'El nombre del ejercicio debe tener al menos 3 caracteres.',
            'exercise_type.required' => 'El tipo de ejercicio es obligatorio.',
            'exercise_type.in' => 'El tipo de ejercicio seleccionado no es válido.',
            'category.required' => 'La categoría del ejercicio es obligatoria.',
            'video_url.url' => 'La URL del video no es válida.',
            'difficulty_level.in' => 'El nivel de dificultad debe ser: beginner, intermediate o advanced.',
        ];
    }

    public function attributes(): array
    {
        return [
            'exercise_type' => 'tipo de ejercicio',
            'category' => 'categoría',
            'muscle_groups' => 'grupos musculares',
            'target_muscle_groups' => 'músculos objetivo',
            'difficulty_level' => 'nivel de dificultad',
            'movement_pattern' => 'patrón de movimiento',
            'instructions' => 'instrucciones',
            'video_url' => 'URL del video',
            'is_active' => 'estado activo',
        ];
    }
}
