<?php

namespace App\Models\Gym;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignedExercise extends Model
{
    use HasFactory;

    protected $table = 'gym_assigned_exercises';

    protected $fillable = [
        'daily_assignment_id',
        'exercise_id',
        'display_order',
        'name',
        'muscle_group',
        'equipment',
        'instructions',
        'tempo',
        'notes',
    ];

    public function dailyAssignment(): BelongsTo
    {
        return $this->belongsTo(DailyAssignment::class, 'daily_assignment_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(AssignedSet::class, 'assigned_exercise_id')
            ->orderBy('set_number');
    }
}
