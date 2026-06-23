<?php

namespace App\Http\Resources\Gym;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExerciseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'exercise_type' => $this->exercise_type,
            'exercise_type_label' => $this->exercise_type_label,
            'category' => $this->category,
            'category_label' => $this->category_label,
            'tags' => $this->tags ?? [],
            'video_url' => $this->video_url,
            'instructions' => $this->instructions,
            'is_active' => (bool) $this->is_active,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'muscle_groups' => $this->muscle_groups,
            'target_muscle_groups' => $this->target_muscle_groups,
            'movement_pattern' => $this->movement_pattern,
            'equipment' => $this->equipment,
            'difficulty_level' => $this->difficulty_level,
        ];
    }
}
