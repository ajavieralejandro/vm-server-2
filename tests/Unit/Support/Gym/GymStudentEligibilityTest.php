<?php

namespace Tests\Unit\Support\Gym;

use App\Models\SocioPadron;
use App\Models\User;
use App\Support\Gym\GymStudentEligibility;
use Tests\TestCase;

class GymStudentEligibilityTest extends TestCase
{
    public function test_padron_enabled_when_hab_controles_positive(): void
    {
        $socio = new SocioPadron([
            'hab_controles' => 1,
            'acceso_full' => false,
        ]);

        $this->assertTrue(GymStudentEligibility::isPadronEnabled($socio));
    }

    public function test_padron_disabled_when_no_flags(): void
    {
        $socio = new SocioPadron([
            'hab_controles' => 0,
            'acceso_full' => false,
        ]);

        $this->assertFalse(GymStudentEligibility::isPadronEnabled($socio));
    }

    public function test_user_enabled_with_student_gym_flag(): void
    {
        $user = new User([
            'student_gym' => true,
            'account_status' => 'active',
            'is_professor' => false,
            'is_admin' => false,
        ]);

        $this->assertTrue(GymStudentEligibility::isUserEnabled($user));
    }
}
