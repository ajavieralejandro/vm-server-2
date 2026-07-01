<?php

namespace App\Support\Gym;

use App\Models\Gym\WeeklyAssignment;
use App\Models\User;

class GymAssignmentAuthorization
{
    public static function isAdmin(User $user): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin();
    }

    /**
     * daily_assignments row with optional psa_professor_id from join.
     *
     * @param  object{assigned_by?: int|null, psa_professor_id?: int|null}  $row
     */
    public static function canManageDailyAssignment(User $user, object $row): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }

        if (!$user->is_professor) {
            return false;
        }

        $userId = (int) $user->id;

        if (isset($row->assigned_by) && $row->assigned_by !== null) {
            return (int) $row->assigned_by === $userId;
        }

        // Legacy: assigned_by null → fallback PSA professor
        if (isset($row->psa_professor_id) && $row->psa_professor_id !== null) {
            return (int) $row->psa_professor_id === $userId;
        }

        return false;
    }

    public static function canManageWeeklyAssignment(User $user, WeeklyAssignment $assignment): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }

        if (!$user->is_professor) {
            return false;
        }

        if ($assignment->created_by === null) {
            return false;
        }

        return (int) $assignment->created_by === (int) $user->id;
    }

    public static function abortUnlessCanManageDailyAssignment(User $user, object $row): void
    {
        if (!self::canManageDailyAssignment($user, $row)) {
            abort(403, 'No tienes permisos para gestionar esta asignación.');
        }
    }

    public static function abortUnlessCanManageWeeklyAssignment(User $user, WeeklyAssignment $assignment): void
    {
        if (!self::canManageWeeklyAssignment($user, $assignment)) {
            abort(403, 'No tienes permisos para gestionar esta asignación semanal.');
        }
    }
}
