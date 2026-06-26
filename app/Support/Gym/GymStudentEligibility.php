<?php

namespace App\Support\Gym;

use App\Models\SocioPadron;
use App\Models\User;

/**
 * Criterio centralizado de socio/alumno habilitado para recibir rutinas de gimnasio.
 *
 * Padrón (socios_padron): hab_controles > 0 OR acceso_full = true.
 * User: cuenta activa, no es profesor/admin, y (student_gym = true OR padrón vinculado habilitado).
 */
class GymStudentEligibility
{
    public static function isPadronEnabled(SocioPadron $socio): bool
    {
        if ((bool) ($socio->acceso_full ?? false)) {
            return true;
        }

        return (int) ($socio->hab_controles ?? 0) > 0;
    }

    public static function padronDisabledReason(SocioPadron $socio): ?string
    {
        if (self::isPadronEnabled($socio)) {
            return null;
        }

        return 'El socio no está habilitado para recibir rutinas (hab_controles/acceso_full).';
    }

    public static function isUserEnabled(User $user): bool
    {
        if ($user->is_professor || $user->is_admin) {
            return false;
        }

        if ($user->account_status !== null && $user->account_status !== 'active') {
            return false;
        }

        if ($user->student_gym === true) {
            return true;
        }

        $padron = self::findPadronForUser($user);
        if ($padron !== null) {
            return self::isPadronEnabled($padron);
        }

        return false;
    }

    public static function userDisabledReason(User $user): ?string
    {
        if (self::isUserEnabled($user)) {
            return null;
        }

        if ($user->is_professor || $user->is_admin) {
            return 'El usuario no es un alumno de gimnasio.';
        }

        if ($user->account_status !== null && $user->account_status !== 'active') {
            return 'La cuenta del alumno no está activa.';
        }

        return 'El alumno no está habilitado para recibir rutinas (student_gym o padrón).';
    }

    public static function findPadronForUser(User $user): ?SocioPadron
    {
        $dni = preg_replace('/\D+/', '', trim((string) ($user->dni ?? '')));
        if ($dni !== '') {
            $byDni = SocioPadron::query()->where('dni', $dni)->first();
            if ($byDni) {
                return $byDni;
            }
        }

        if (!empty($user->barcode)) {
            return SocioPadron::query()->where('barcode', $user->barcode)->first();
        }

        if (!empty($user->socio_id)) {
            return SocioPadron::query()
                ->where('sid', (string) $user->socio_id)
                ->orWhere('dni', (string) $user->socio_id)
                ->first();
        }

        return null;
    }

    /**
     * Scope query de socios_padron a registros habilitados.
     */
    public static function scopeEnabledPadron($query)
    {
        return $query->where(function ($q) {
            $q->where('hab_controles', '>', 0)
                ->orWhere('acceso_full', true);
        });
    }
}
