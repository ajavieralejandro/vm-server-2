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

    public function syncProfessorStudents(int $professorId, array $studentIds, ?int $assignedBy)
    {
        return DB::transaction(function () use ($professorId, $studentIds, $assignedBy) {
            $existing = ProfessorStudentAssignment::where('professor_id', $professorId)->pluck('student_id')->toArray();
            $toAdd = array_diff($studentIds, $existing);
            $toRemove = array_diff($existing, $studentIds);

            // Delete assignments not in new list
            ProfessorStudentAssignment::where('professor_id', $professorId)
                ->whereIn('student_id', $toRemove)
                ->delete();

            // Add new assignments
            $now = now();
            foreach ($toAdd as $studentId) {
                ProfessorStudentAssignment::create([
                    'professor_id' => $professorId,
                    'student_id' => $studentId,
                    'assigned_by' => $assignedBy,
                    'start_date' => $now,
                    'status' => 'active',
                ]);
            }

            return ProfessorStudentAssignment::with(['student', 'professor'])
                ->where('professor_id', $professorId)
                ->get();
        });
    }
}
