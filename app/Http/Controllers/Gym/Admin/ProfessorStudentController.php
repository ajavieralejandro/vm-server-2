<?php

namespace App\Http\Controllers\Gym\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Services\Gym\ProfessorStudentAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfessorStudentController extends Controller
{
    public function index($professorId)
    {
        $professor = User::where('id', $professorId)->where('is_professor', true)->firstOrFail();
        $assignments = ProfessorStudentAssignment::with(['student', 'professor'])
            ->where('professor_id', $professorId)
            ->get();
        return response()->json(['ok' => true, 'data' => $assignments]);
    }

    public function assign($professorId, Request $request)
    {
        $professor = User::where('id', $professorId)->where('is_professor', true)->firstOrFail();
        $data = $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'integer|exists:users,id',
        ]);
        $studentIds = $data['student_ids'];
        $assignedBy = auth()->id();
        $assignments = app(ProfessorStudentAssignmentService::class)
            ->syncProfessorStudents($professorId, $studentIds, $assignedBy);
        return response()->json([
            'ok' => true,
            'message' => 'Asignación actualizada',
            'data' => $assignments
        ]);
    }
}
