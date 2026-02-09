<?php

namespace App\Services\Gym;

use App\Models\Gym\ProfessorStudentAssignment;
use Illuminate\Support\Facades\DB;

class ProfessorStudentAssignmentService
{
    public function listByProfessor(int $professorId)
    {
        return ProfessorStudentAssignment::with(['student', 'professor'])
            ->where('professor_id', $professorId)
            ->get();
    }

    /**
     * REPLACE:
     * Deja EXACTAMENTE los studentIds. Lo que no está => se marca inactive (no delete duro).
     */
    public function syncProfessorStudents(int $professorId, array $studentIds, ?int $assignedBy): array
    {
        $studentIds = $this->normalizeIds($studentIds);

        return DB::transaction(function () use ($professorId, $studentIds, $assignedBy) {
            $now = now();

            $existing = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $toAdd = array_values(array_diff($studentIds, $existing));
            $toRemove = array_values(array_diff($existing, $studentIds));

            // Soft-remove (no borrar)
            $removedCount = 0;
            if (!empty($toRemove)) {
                $removedCount = ProfessorStudentAssignment::query()
                    ->where('professor_id', $professorId)
                    ->whereIn('student_id', $toRemove)
                    ->update([
                        'status' => 'inactive',
                        'end_date' => $now,
                        'updated_at' => $now,
                    ]);
            }

            // Add/reactivate
            $assignedCount = 0;
            foreach ($toAdd as $studentId) {
                $assignment = ProfessorStudentAssignment::query()
                    ->where('professor_id', $professorId)
                    ->where('student_id', $studentId)
                    ->first();

                if ($assignment) {
                    // Reactivar si estaba inactivo
                    if ($assignment->status !== 'active') {
                        $assignment->update([
                            'status' => 'active',
                            'start_date' => $assignment->start_date ?? $now,
                            'end_date' => null,
                            'assigned_by' => $assignment->assigned_by ?? $assignedBy,
                        ]);
                        $assignedCount++;
                    }
                    continue;
                }

                ProfessorStudentAssignment::create([
                    'professor_id' => $professorId,
                    'student_id' => $studentId,
                    'assigned_by' => $assignedBy,
                    'start_date' => $now,
                    'end_date' => null,
                    'status' => 'active',
                ]);
                $assignedCount++;
            }

            $data = ProfessorStudentAssignment::with(['student', 'professor'])
                ->where('professor_id', $professorId)
                ->orderBy('id')
                ->get();

            return [
                'ok' => true,
                'mode' => 'replace',
                'professor_id' => $professorId,
                'assigned_count' => (int) $assignedCount,
                'removed_count' => (int) $removedCount,
                'data' => $data,
            ];
        });
    }

    /**
     * MERGE / ATTACH:
     * Agrega studentIds sin borrar los existentes.
     * Ideal para auto-asignación del profesor (uno por vez) y para "professor_socio" espejo.
     */
    public function attachProfessorStudents(int $professorId, array $studentIds, ?int $assignedBy): array
    {
        $studentIds = $this->normalizeIds($studentIds);

        return DB::transaction(function () use ($professorId, $studentIds, $assignedBy) {
            $now = now();

            $assignedCount = 0;

            foreach ($studentIds as $studentId) {
                $assignment = ProfessorStudentAssignment::query()
                    ->where('professor_id', $professorId)
                    ->where('student_id', $studentId)
                    ->first();

                if ($assignment) {
                    if ($assignment->status !== 'active') {
                        $assignment->update([
                            'status' => 'active',
                            'end_date' => null,
                            'assigned_by' => $assignment->assigned_by ?? $assignedBy,
                            'start_date' => $assignment->start_date ?? $now,
                        ]);
                        $assignedCount++;
                    }
                    continue;
                }

                ProfessorStudentAssignment::create([
                    'professor_id' => $professorId,
                    'student_id' => $studentId,
                    'assigned_by' => $assignedBy,
                    'start_date' => $now,
                    'end_date' => null,
                    'status' => 'active',
                ]);
                $assignedCount++;
            }

            $data = ProfessorStudentAssignment::with(['student', 'professor'])
                ->where('professor_id', $professorId)
                ->orderBy('id')
                ->get();

            return [
                'ok' => true,
                'mode' => 'merge',
                'professor_id' => $professorId,
                'assigned_count' => (int) $assignedCount,
                'removed_count' => 0,
                'data' => $data,
            ];
        });
    }

    /**
     * DETACH:
     * Marca inactive a esos studentIds.
     */
    public function detachProfessorStudents(int $professorId, array $studentIds): array
    {
        $studentIds = $this->normalizeIds($studentIds);

        return DB::transaction(function () use ($professorId, $studentIds) {
            $now = now();

            $removedCount = 0;
            if (!empty($studentIds)) {
                $removedCount = ProfessorStudentAssignment::query()
                    ->where('professor_id', $professorId)
                    ->whereIn('student_id', $studentIds)
                    ->update([
                        'status' => 'inactive',
                        'end_date' => $now,
                        'updated_at' => $now,
                    ]);
            }

            $data = ProfessorStudentAssignment::with(['student', 'professor'])
                ->where('professor_id', $professorId)
                ->orderBy('id')
                ->get();

            return [
                'ok' => true,
                'mode' => 'detach',
                'professor_id' => $professorId,
                'assigned_count' => 0,
                'removed_count' => (int) $removedCount,
                'data' => $data,
            ];
        });
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_map(fn ($v) => (int) $v, $ids);
        $ids = array_filter($ids, fn ($v) => $v > 0);
        return array_values(array_unique($ids));
    }
}
