<?php

return [

    'types' => [
        'fuerza' => 'Fuerza',
        'movilidad' => 'Movilidad',
        'estabilidad' => 'Estabilidad',
        'resistencia' => 'Resistencia',
    ],

    'categories' => [
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
    ],

    'tags' => [
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
    ],

    'defaults' => [
        'exercise_type' => 'fuerza',
        'category' => 'dinamicos',
        'is_active' => true,
    ],

];
