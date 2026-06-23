<?php

namespace Tests\Unit\Support\Gym;

use App\Support\Gym\ExerciseDomainConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExerciseDomainConfigTest extends TestCase
{
    public function test_type_slugs_include_new_domain_values(): void
    {
        $this->assertSame(
            ['fuerza', 'movilidad', 'estabilidad', 'resistencia'],
            ExerciseDomainConfig::typeSlugs()
        );
    }

    #[DataProvider('validTypeCategoryPairs')]
    public function test_category_belongs_to_type(string $type, string $category): void
    {
        $this->assertContains(
            $category,
            ExerciseDomainConfig::categorySlugsForType($type)
        );
    }

    public static function validTypeCategoryPairs(): array
    {
        return [
            ['estabilidad', 'anti_flexion'],
            ['fuerza', 'accesorios_mmii'],
            ['movilidad', 'miembro_superior'],
            ['resistencia', 'carrera'],
        ];
    }

    public function test_fuerza_does_not_allow_estabilidad_category(): void
    {
        $this->assertNotContains(
            'anti_flexion',
            ExerciseDomainConfig::categorySlugsForType('fuerza')
        );
    }
}
