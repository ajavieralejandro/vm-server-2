<?php

namespace App\Models\Gym;

use App\Models\User;
use App\Support\Gym\ExerciseDomainConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    use HasFactory;

    protected $table = 'gym_exercises';

    protected $fillable = [
        'name',
        'description',
        'exercise_type',
        'category',
        'muscle_groups',
        'target_muscle_groups',
        'movement_pattern',
        'equipment',
        'difficulty_level',
        'tags',
        'instructions',
        'video_url',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'muscle_groups' => 'array',
        'target_muscle_groups' => 'array',
        'tags' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dailyTemplateExercises(): HasMany
    {
        return $this->hasMany(DailyTemplateExercise::class, 'exercise_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('exercise_type', $type);
    }

    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function getExerciseTypeLabelAttribute(): ?string
    {
        if (! $this->exercise_type) {
            return null;
        }

        return ExerciseDomainConfig::typeLabel($this->exercise_type);
    }

    public function getCategoryLabelAttribute(): ?string
    {
        if (! $this->exercise_type || ! $this->category) {
            return null;
        }

        return ExerciseDomainConfig::categoryLabel($this->exercise_type, $this->category);
    }
}
