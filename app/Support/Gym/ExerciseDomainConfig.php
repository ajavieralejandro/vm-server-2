<?php

namespace App\Support\Gym;

class ExerciseDomainConfig
{
    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        $types = config('gym_exercises.types', []);

        return ! empty($types) ? $types : self::fallbackTypes();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function categories(): array
    {
        $categories = config('gym_exercises.categories', []);

        return ! empty($categories) ? $categories : self::fallbackCategories();
    }

    /**
     * @return array<int, string>
     */
    public static function tags(): array
    {
        $tags = config('gym_exercises.tags', []);

        return ! empty($tags) ? $tags : self::fallbackTags();
    }

    /**
     * @return array<int, string>
     */
    public static function typeSlugs(): array
    {
        return array_keys(self::types());
    }

    /**
     * @return array<int, string>
     */
    public static function categorySlugsForType(?string $type): array
    {
        if (! $type) {
            return [];
        }

        return array_keys(self::categories()[$type] ?? []);
    }

    public static function typeLabel(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        return self::types()[$type] ?? null;
    }

    public static function categoryLabel(?string $type, ?string $category): ?string
    {
        if (! $type || ! $category) {
            return null;
        }

        return self::categories()[$type][$category] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function fallbackTypes(): array
    {
        return [
            'fuerza' => 'Fuerza',
            'movilidad' => 'Movilidad',
            'estabilidad' => 'Estabilidad',
            'resistencia' => 'Resistencia',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function fallbackCategories(): array
    {
        return [
            'fuerza' => [
                'dominante_rodilla' => 'Dominante de rodilla',
                'dominante_cadera' => 'Dominante de cadera',
                'empuje_vertical_horizontal' => 'Empuje vertical/horizontal',
                'traccion_vertical_horizontal' => 'Tracción vertical/horizontal',
                'saltos' => 'Saltos',
                'dinamicos' => 'Dinámicos',
                'accesorios_mmss' => 'Accesorios MMSS / miembros superiores',
                'accesorios_mmii' => 'Accesorios MMII / miembros inferiores',
                'lanzamiento' => 'Lanzamiento',
            ],
            'movilidad' => [
                'miembro_superior' => 'Miembro superior',
                'miembro_inferior' => 'Miembro inferior',
            ],
            'estabilidad' => [
                'anti_extension' => 'Anti extensión',
                'anti_flexion' => 'Anti flexión',
                'anti_rotacion' => 'Anti rotación',
                'anti_flexion_lateral' => 'Anti flexión lateral',
            ],
            'resistencia' => [
                'carrera' => 'Carrera',
                'bicicleta' => 'Bicicleta',
                'remo' => 'Remo',
                'escaladora' => 'Escaladora',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function fallbackTags(): array
    {
        return [
            'tren_superior',
            'tren_inferior',
            'core',
            'principiante',
            'intermedio',
            'avanzado',
            'tecnica',
            'potencia',
            'movilidad',
            'rehabilitacion',
        ];
    }
}
