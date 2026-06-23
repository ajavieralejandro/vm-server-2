<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Gym\Exercise;

class GymExercisesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpiar ejercicios existentes
        Exercise::query()->delete();
        
        echo "🏋️ Creando 4 ejercicios de gimnasio...\n\n";
        
        // EJERCICIO 1: Press de Banca
        $exercise1 = Exercise::create([
            'name' => 'Press de Banca con Barra',
            'description' => 'Ejercicio compuesto fundamental para el desarrollo del pecho, hombros y tríceps. Es uno de los movimientos básicos del entrenamiento de fuerza.',
            'exercise_type' => 'fuerza',
            'category' => 'empuje_vertical_horizontal',
            'muscle_groups' => ['pecho', 'tríceps', 'hombros'],
            'target_muscle_groups' => ['pectoral mayor', 'pectoral menor', 'tríceps', 'deltoides anterior'],
            'movement_pattern' => 'push horizontal',
            'equipment' => 'barra, banco plano, discos',
            'difficulty_level' => 'intermediate',
            'tags' => ['compuesto', 'fuerza', 'masa muscular', 'push', 'pecho'],
            'instructions' => 'Acuéstate en el banco plano con los pies firmemente apoyados en el suelo. Agarra la barra con un agarre ligeramente más ancho que los hombros. Baja la barra de forma controlada hasta tocar el pecho a la altura de los pezones. Presiona la barra hacia arriba hasta la extensión completa de los brazos, manteniendo los omóplatos retraídos durante todo el movimiento.',
            'is_active' => true,
        ]);
        echo "✅ Ejercicio 1: {$exercise1->name} (ID: {$exercise1->id})\n";
        
        // EJERCICIO 2: Peso Muerto Convencional
        $exercise2 = Exercise::create([
            'name' => 'Peso Muerto Convencional',
            'description' => 'Ejercicio compuesto rey para el desarrollo de la cadena posterior. Trabaja prácticamente todos los músculos del cuerpo con énfasis en piernas, espalda baja y core.',
            'exercise_type' => 'fuerza',
            'category' => 'dominante_cadera',
            'muscle_groups' => ['piernas', 'espalda', 'glúteos', 'core'],
            'target_muscle_groups' => ['erectores espinales', 'glúteo mayor', 'isquiotibiales', 'cuádriceps', 'trapecio', 'dorsal ancho'],
            'movement_pattern' => 'hinge',
            'equipment' => 'barra, discos',
            'difficulty_level' => 'advanced',
            'tags' => ['compuesto', 'fuerza', 'cadena posterior', 'pull', 'piernas', 'espalda'],
            'instructions' => 'Colócate con los pies separados al ancho de caderas, con la barra sobre el medio del pie. Agarra la barra con las manos justo fuera de las piernas. Mantén la espalda neutra, pecho hacia arriba. Empuja el suelo con los pies mientras extiendes simultáneamente caderas y rodillas. Mantén la barra pegada al cuerpo durante todo el recorrido. Desciende de forma controlada invirtiendo el movimiento.',
            'is_active' => true,
        ]);
        echo "✅ Ejercicio 2: {$exercise2->name} (ID: {$exercise2->id})\n";
        
        // EJERCICIO 3: Sentadilla Trasera
        $exercise3 = Exercise::create([
            'name' => 'Sentadilla Trasera (Back Squat)',
            'description' => 'El rey de los ejercicios para piernas. Desarrolla fuerza, masa muscular y potencia en todo el tren inferior. Fundamental en cualquier programa de entrenamiento.',
            'exercise_type' => 'fuerza',
            'category' => 'dominante_rodilla',
            'muscle_groups' => ['piernas', 'glúteos', 'core'],
            'target_muscle_groups' => ['cuádriceps', 'glúteo mayor', 'isquiotibiales', 'aductores', 'erectores espinales', 'abdominales'],
            'movement_pattern' => 'squat',
            'equipment' => 'barra, rack, discos',
            'difficulty_level' => 'intermediate',
            'tags' => ['compuesto', 'fuerza', 'masa muscular', 'piernas', 'squat'],
            'instructions' => 'Coloca la barra en la parte superior de la espalda (trapecios). Pies separados al ancho de hombros, dedos ligeramente hacia afuera. Mantén el pecho hacia arriba y la espalda neutra. Desciende empujando las caderas hacia atrás y flexionando las rodillas hasta que los muslos estén al menos paralelos al suelo. Empuja el suelo con los pies para volver a la posición inicial, manteniendo el core activado.',
            'is_active' => true,
        ]);
        echo "✅ Ejercicio 3: {$exercise3->name} (ID: {$exercise3->id})\n";
        
        // EJERCICIO 4: Dominadas
        $exercise4 = Exercise::create([
            'name' => 'Dominadas (Pull-ups)',
            'description' => 'Ejercicio de tracción vertical con peso corporal. Excelente para desarrollar fuerza y masa muscular en la espalda, hombros y brazos.',
            'exercise_type' => 'fuerza',
            'category' => 'traccion_vertical_horizontal',
            'muscle_groups' => ['espalda', 'bíceps', 'hombros'],
            'target_muscle_groups' => ['dorsal ancho', 'trapecio medio', 'romboides', 'bíceps braquial', 'braquial anterior', 'deltoides posterior'],
            'movement_pattern' => 'pull vertical',
            'equipment' => 'barra de dominadas',
            'difficulty_level' => 'intermediate',
            'tags' => ['compuesto', 'peso corporal', 'espalda', 'pull', 'calistenia'],
            'instructions' => 'Cuelga de una barra con agarre prono (palmas hacia adelante) ligeramente más ancho que los hombros. Retrae los omóplatos y comienza a tirar con los codos hacia abajo y atrás. Eleva el cuerpo hasta que la barbilla supere la barra. Desciende de forma controlada hasta la extensión completa de los brazos. Mantén el core activado y evita balancearte.',
            'is_active' => true,
        ]);
        echo "✅ Ejercicio 4: {$exercise4->name} (ID: {$exercise4->id})\n";
        
        echo "\n🎉 ¡4 ejercicios creados exitosamente!\n\n";
        
        echo "📊 RESUMEN DE EJERCICIOS:\n";
        echo str_repeat("=", 70) . "\n";
        
        $exercises = Exercise::all();
        foreach ($exercises as $i => $ex) {
            echo "\n" . ($i + 1) . ". {$ex->name}\n";
            echo "   Grupos musculares: " . implode(", ", $ex->muscle_groups) . "\n";
            echo "   Patrón: {$ex->movement_pattern}\n";
            echo "   Dificultad: {$ex->difficulty_level}\n";
            echo "   Equipamiento: {$ex->equipment}\n";
        }
        
        echo "\n" . str_repeat("=", 70) . "\n";
    }
}
