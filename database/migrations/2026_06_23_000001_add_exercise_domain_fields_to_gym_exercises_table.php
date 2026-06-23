<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_exercises', function (Blueprint $table) {
            if (! Schema::hasColumn('gym_exercises', 'exercise_type')) {
                $table->string('exercise_type')->nullable()->after('name')->index();
            }

            if (! Schema::hasColumn('gym_exercises', 'category')) {
                $table->string('category')->nullable()->after('exercise_type')->index();
            }

            if (! Schema::hasColumn('gym_exercises', 'video_url')) {
                $table->string('video_url', 500)->nullable()->after('instructions');
            }

            if (! Schema::hasColumn('gym_exercises', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('video_url')->index();
            }

            if (! Schema::hasColumn('gym_exercises', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            }
        });

        $this->backfillExistingExercises();
    }

    public function down(): void
    {
        Schema::table('gym_exercises', function (Blueprint $table) {
            if (Schema::hasColumn('gym_exercises', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }

            foreach (['is_active', 'video_url', 'category', 'exercise_type'] as $column) {
                if (Schema::hasColumn('gym_exercises', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillExistingExercises(): void
    {
        $defaults = config('gym_exercises.defaults', [
            'exercise_type' => 'fuerza',
            'category' => 'dinamicos',
            'is_active' => true,
        ]);

        DB::table('gym_exercises')
            ->orderBy('id')
            ->chunkById(100, function ($exercises) use ($defaults) {
                foreach ($exercises as $exercise) {
                    $updates = [];

                    if (empty($exercise->exercise_type) || empty($exercise->category)) {
                        [$type, $category] = $this->mapMovementPatternToDomain($exercise->movement_pattern ?? '');
                        $updates['exercise_type'] = $exercise->exercise_type ?: $type;
                        $updates['category'] = $exercise->category ?: $category;
                    }

                    if ($exercise->is_active === null) {
                        $updates['is_active'] = $defaults['is_active'];
                    }

                    if (! empty($updates)) {
                        DB::table('gym_exercises')
                            ->where('id', $exercise->id)
                            ->update($updates);
                    }
                }
            });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function mapMovementPatternToDomain(?string $movementPattern): array
    {
        $defaults = config('gym_exercises.defaults', [
            'exercise_type' => 'fuerza',
            'category' => 'dinamicos',
        ]);

        $pattern = strtolower(trim((string) $movementPattern));

        if ($pattern === '') {
            return [$defaults['exercise_type'], $defaults['category']];
        }

        if (str_contains($pattern, 'squat') || str_contains($pattern, 'sentadilla')) {
            return ['fuerza', 'dominante_rodilla'];
        }

        if (str_contains($pattern, 'hinge') || str_contains($pattern, 'bisagra') || str_contains($pattern, 'deadlift') || str_contains($pattern, 'peso muerto')) {
            return ['fuerza', 'dominante_cadera'];
        }

        if (
            str_contains($pattern, 'push')
            || str_contains($pattern, 'press')
            || str_contains($pattern, 'empuje')
            || str_contains($pattern, 'empuja')
        ) {
            return ['fuerza', 'empuje_vertical_horizontal'];
        }

        if (
            str_contains($pattern, 'pull')
            || str_contains($pattern, 'row')
            || str_contains($pattern, 'remo')
            || str_contains($pattern, 'tracc')
            || str_contains($pattern, 'dominada')
        ) {
            return ['fuerza', 'traccion_vertical_horizontal'];
        }

        if (str_contains($pattern, 'jump') || str_contains($pattern, 'salto')) {
            return ['fuerza', 'saltos'];
        }

        if (str_contains($pattern, 'throw') || str_contains($pattern, 'lanz')) {
            return ['fuerza', 'lanzamiento'];
        }

        if (str_contains($pattern, 'anti-ext') || str_contains($pattern, 'anti ext')) {
            return ['estabilidad', 'anti_extension'];
        }

        if (str_contains($pattern, 'anti-flex') || str_contains($pattern, 'anti flex')) {
            return ['estabilidad', 'anti_flexion'];
        }

        if (str_contains($pattern, 'anti-rot') || str_contains($pattern, 'anti rot')) {
            return ['estabilidad', 'anti_rotacion'];
        }

        if (str_contains($pattern, 'plancha') || str_contains($pattern, 'core') || str_contains($pattern, 'isometric')) {
            return ['estabilidad', 'anti_extension'];
        }

        if (str_contains($pattern, 'carrera') || str_contains($pattern, 'run')) {
            return ['resistencia', 'carrera'];
        }

        if (str_contains($pattern, 'bici') || str_contains($pattern, 'bike')) {
            return ['resistencia', 'bicicleta'];
        }

        if (str_contains($pattern, 'remo erg') || str_contains($pattern, 'rower')) {
            return ['resistencia', 'remo'];
        }

        if (str_contains($pattern, 'escaladora') || str_contains($pattern, 'stair')) {
            return ['resistencia', 'escaladora'];
        }

        if (str_contains($pattern, 'movil') || str_contains($pattern, 'stretch') || str_contains($pattern, 'flexibil')) {
            return ['movilidad', 'miembro_inferior'];
        }

        return [$defaults['exercise_type'], $defaults['category']];
    }
};
