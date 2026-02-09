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
            ->orderBy('id')
            ->get();
    }

    /**
     * REPLACE:
     * Deja EXACTAMENTE los studentIds activos.
     * Los que no están => status=inactive (soft-remove).
     */
    public function syncProfessorStudents(int $professorId, array $studentIds, ?int $assignedBy): array
    {
        $studentIds = $this->normalizeIds($studentIds);

        return DB::transaction(function () use ($professorId, $studentIds, $assignedBy) {
            $now = now();

            // Traigo TODOS los existentes (activos e inactivos) para evitar N+1
            $existingRows = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->get()
                ->keyBy(fn ($row) => (int) $row->student_id);

            $existingIds = $existingRows->keys()->map(fn ($v) => (int) $v)->toArray();

            $toAddOrReactivate = array_values(array_diff($studentIds, $existingIds));
            $toMaybeReactivate = array_values(array_intersect($studentIds, $existingIds));
            $toRemove = array_values(array_diff($existingIds, $studentIds));

            // Soft-remove (no borrar)
            $removedCount = 0;
            if (!empty($toRemove)) {
                $removedCount = ProfessorStudentAssignment::query()
                    ->where('professor_id', $professorId)
                    ->whereIn('student_id', $toRemove)
                    ->where('status', '!=', 'inactive')
                    ->update([
                        'status' => 'inactive',
                        'end_date' => $now,
                        'updated_at' => $now,
                    ]);
            }

            $assignedCount = 0;

            // Reactivar los que ya existían pero estaban inactive
            foreach ($toMaybeReactivate as $studentId) {
                $row = $existingRows[(int)$studentId] ?? null;
                if ($row && $row->status !== 'active') {
                    $row->update([
                        'status' => 'active',
                        'end_date' => null,
                        'start_date' => $row->start_date ?? $now,
                        'assigned_by' => $row->assigned_by ?? $assignedBy,
                    ]);
                    $assignedCount++;
                }
            }

            // Crear los que no existen
            if (!empty($toAddOrReactivate)) {
                $insert = [];
                foreach ($toAddOrReactivate as $studentId) {
                    $insert[] = [
                        'professor_id' => $professorId,
                        'student_id' => (int) $studentId,
                        'assigned_by' => $assignedBy,
                        'start_date' => $now,
                        'end_date' => null,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $assignedCount++;
                }
                ProfessorStudentAssignment::query()->insert($insert);
            }

            $data = $this->listByProfessor($professorId);

            return [
                'ok' => true,
                'mode' => 'replace',
                'professor_id' => (int) $professorId,
                'assigned_count' => (int) $assignedCount,
                'removed_count' => (int) $removedCount,
                'data' => $data,
            ];
        });
    }

    /**
     * MERGE / ATTACH:
     * Agrega studentIds sin borrar existentes.
     */
    public function attachProfessorStudents(int $professorId, array $studentIds, ?int $assignedBy): array
    {
        $studentIds = $this->normalizeIds($studentIds);

        return DB::transaction(function () use ($professorId, $studentIds, $assignedBy) {
            $now = now();

            if (empty($studentIds)) {
                return [
                    'ok' => true,
                    'mode' => 'merge',
                    'professor_id' => (int) $professorId,
                    'assigned_count' => 0,
                    'removed_count' => 0,
                    'data' => $this->listByProfessor($professorId),
                ];
            }

            $existingRows = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->whereIn('student_id', $studentIds)
                ->get()
                ->keyBy(fn ($row) => (int) $row->student_id);

            $assignedCount = 0;
            $insert = [];

            foreach ($studentIds as $studentId) {
                $studentId = (int) $studentId;
                $row = $existingRows[$studentId] ?? null;

                if ($row) {
                    if ($row->status !== 'active') {
                        $row->update([
                            'status' => 'active',
                            'end_date' => null,
                            'start_date' => $row->start_date ?? $now,
                            'assigned_by' => $row->assigned_by ?? $assignedBy,
                        ]);
                        $assignedCount++;
                    }
                    continue;
                }

                $insert[] = [
                    'professor_id' => $professorId,
                    'student_id' => $studentId,
                    'assigned_by' => $assignedBy,
                    'start_date' => $now,
                    'end_date' => null,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $assignedCount++;
            }

            if (!empty($insert)) {
                ProfessorStudentAssignment::query()->insert($insert);
            }

            $data = $this->listByProfessor($professorId);

            return [
                'ok' => true,
                'mode' => 'merge',
                'professor_id' => (int) $professorId,
                'assigned_count' => (int) $assignedCount,
                'removed_count' => 0,
                'data' => $data,
            ];
        });
    }

    /**
     * DETACH:
     * Marca inactive a esos studentIds (soft).
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
                    ->where('status', '!=', 'inactive')
                    ->update([
                        'status' => 'inactive',
                        'end_date' => $now,
                        'updated_at' => $now,
                    ]);
            }

            $data = $this->listByProfessor($professorId);

            return [
                'ok' => true,
                'mode' => 'detach',
                'professor_id' => (int) $professorId,
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
