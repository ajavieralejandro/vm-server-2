<?php

namespace App\Http\Controllers\Gym\Professor;

use App\Http\Controllers\Controller;
use App\Services\Gym\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\Models\User;
use App\Models\SocioPadron;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;

class AssignmentController extends Controller
{
    public function __construct(
        private AssignmentService $assignmentService
    ) {}

    /**
     * GET /api/professor/my-students?per_page=20&page=1&search=
     * Lista socios asignados al profesor (desde pivot professor_socio + padron),
     * con estructura compatible con el front (assignment.student.*)
     */
    public function myStudents(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min(200, $perPage));
            $page    = (int) $request->query('page', 1);
            $search  = trim((string) $request->query('search', $request->query('q', '')));

            $professorId = (int) auth()->id();

            $baseQuery = SocioPadron::query()
                ->join('professor_socio', 'professor_socio.socio_id', '=', 'socios_padron.id')
                ->where('professor_socio.professor_id', $professorId)
                ->select([
                    'socios_padron.id',
                    'socios_padron.dni',
                    'socios_padron.sid',
                    'socios_padron.apynom',
                    'socios_padron.barcode',
                    'socios_padron.saldo',
                    'socios_padron.semaforo',
                    'socios_padron.hab_controles',
                ])
                ->orderBy('socios_padron.apynom')
                ->orderBy('socios_padron.dni');

            if ($search !== '') {
                $baseQuery->where(function ($w) use ($search) {
                    $w->where('socios_padron.dni', 'like', "%{$search}%")
                      ->orWhere('socios_padron.sid', 'like', "%{$search}%")
                      ->orWhere('socios_padron.apynom', 'like', "%{$search}%");
                });
            }

            $paginator = $baseQuery->paginate($perPage, ['*'], 'page', $page);

            $data = $paginator->getCollection()->map(function ($socio) use ($professorId) {
                return [
                    // ⚠️ Ojo: este "id" es pseudo (socio_padron.id) para el front
                    'id' => (int) $socio->id,
                    'professor_id' => $professorId,
                    'student_id' => (int) $socio->id, // pseudo
                    'status' => 'active',
                    'start_date' => null,
                    'end_date' => null,
                    'admin_notes' => null,
                    'created_at' => null,
                    'updated_at' => null,

                    'student' => [
                        'id' => (int) $socio->id, // socio_padron.id (pseudo para front)
                        'dni' => $socio->dni,
                        'name' => (string) ($socio->apynom ?? ''),
                        'email' => null,
                        'user_type' => 'socio',
                        'type_label' => 'Socio',
                        'socio_id' => (string) ($socio->sid ?? null),
                        'socio_n' => (string) ($socio->sid ?? null),
                        'barcode' => $socio->barcode,
                        'saldo' => $socio->saldo,
                        'semaforo' => $socio->semaforo,
                        'hab_controles' => $socio->hab_controles,
                        'foto_url' => null,
                        'avatar_path' => null,
                    ],

                    'template_assignments' => [],
                ];
            })->values();

            $out = new LengthAwarePaginator(
                $data,
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );

            return response()->json($out);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estudiantes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/professor/students/{studentId}/template-assignments
     * studentId puede venir como:
     * - users.id (ideal)
     * - socios_padron.id (lo que te devuelve myStudents)
     */
    public function studentTemplateAssignments(Request $request, int $studentId): JsonResponse
    {
        try {
            $professorId = (int) auth()->id();

            // 1) Interpretar studentId como users.id
            $user = User::find($studentId);

            // 2) Si no existe, interpretarlo como socios_padron.id
            if (!$user) {
                $socio = SocioPadron::find($studentId);

                if (!$socio) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Alumno no encontrado (ni user ni socio padron)'
                    ], 404);
                }

                // Validar asignación en pivot
                $isAssigned = DB::table('professor_socio')
                    ->where('professor_id', $professorId)
                    ->where('socio_id', (int) $socio->id)
                    ->exists();

                if (!$isAssigned) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Alumno no asignado a este profesor'
                    ], 403);
                }

                // Crear/actualizar user espejo (FK real)
                $user = $this->ensureUserFromSocioPadron($socio);
            }

            // 3) Buscar/crear ProfessorStudentAssignment (FK a users)
            $psa = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->where('student_id', (int) $user->id)
                ->where('status', 'active')
                ->first();

            if (!$psa) {
                $psa = ProfessorStudentAssignment::create([
                    'professor_id' => $professorId,
                    'student_id'   => (int) $user->id,
                    'assigned_by'  => $professorId,
                    'status'       => 'active',
                    'start_date'   => now(),
                    'end_date'     => null,
                    'admin_notes'  => null,
                ]);
            }

            // 4) Plantillas asignadas a esa relación
            $assignments = TemplateAssignment::with(['dailyTemplate'])
                ->where('professor_student_assignment_id', (int) $psa->id)
                ->orderByDesc('start_date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $assignments,
                'meta' => [
                    'professor_student_assignment_id' => (int) $psa->id,
                    'student_user_id' => (int) $user->id,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener plantillas del alumno',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/professor/template-assignments
     * Body:
     * - professor_student_assignment_id  (puede venir como PSA real o como socio_padron.id)
     * - daily_template_id
     * - start_date (opcional)
     * - end_date (opcional)
     * - frequency (opcional array [0..6])
     * - professor_notes (opcional)
     */
   use App\Models\Gym\DailyAssignment; // <- el modelo que pega a daily_assignments

public function assignTemplate(Request $request): JsonResponse
{
    try {
        $professorId = (int) auth()->id();

        $validated = $request->validate([
            'professor_student_assignment_id' => 'required|integer',
            'daily_template_id' => 'required|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'frequency' => 'nullable|array|min:1',
            'frequency.*' => 'integer|between:0,6',
            'professor_notes' => 'nullable|string|max:1000',
        ]);

        $incoming = (int) $validated['professor_student_assignment_id'];
        $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);

        $assignment = DailyAssignment::create([
            'professor_student_assignment_id' => $psaId,
            'daily_template_id' => (int) $validated['daily_template_id'],
            'start_date' => $validated['start_date'] ?? now()->startOfDay(),
            'end_date' => $validated['end_date'] ?? null,
            'frequency' => $validated['frequency'] ?? null,
            'professor_notes' => $validated['professor_notes'] ?? null,
            'status' => 'active',

            // ✅ FIX
            'assigned_by' => $professorId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plantilla asignada correctamente',
            'data' => $assignment,
        ]);

    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al asignar plantilla',
            'error' => $e->getMessage(),
        ], 422);
    }
}

    /**
     * ✅ Si el front manda el "id" que viene de myStudents() (socio_padron.id),
     * este método lo convierte a un id REAL de professor_student_assignments.
     *
     * Acepta:
     * - incomingId = professor_student_assignments.id (real)
     * - incomingId = socio_padron.id (pseudo)
     */
    private function resolveProfessorStudentAssignmentId(int $incomingId, int $professorId): int
    {
        // Caso A) ID real de professor_student_assignments
        $psa = ProfessorStudentAssignment::query()
            ->where('id', $incomingId)
            ->first();

        if ($psa) {
            if ((int) $psa->professor_id !== $professorId) {
                abort(403, 'La asignación no pertenece a este profesor');
            }
            return (int) $psa->id;
        }

        // Caso B) socio_padron.id
        $socioPadronId = $incomingId;

        $isAssigned = DB::table('professor_socio')
            ->where('professor_id', $professorId)
            ->where('socio_id', $socioPadronId)
            ->exists();

        if (!$isAssigned) {
            abort(403, 'El socio no está asignado a este profesor');
        }

        $socio = SocioPadron::query()->findOrFail($socioPadronId);

        // ✅ crear/obtener User socio (para cumplir FK users.id)
        $userSocio = $this->ensureUserFromSocioPadron($socio);

        // ✅ crear/obtener PSA REAL usando users.id
        $psa = ProfessorStudentAssignment::query()->firstOrCreate(
            [
                'professor_id' => $professorId,
                'student_id'   => (int) $userSocio->id,
            ],
            [
                'assigned_by'  => $professorId,
                'status'       => 'active',
                'start_date'   => now(),
                'end_date'     => null,
                'admin_notes'  => null,
            ]
        );

        if ($psa->status !== 'active') {
            $psa->status = 'active';
            $psa->end_date = null;
            $psa->save();
        }

        return (int) $psa->id;
    }

    private function ensureUserFromSocioPadron(SocioPadron $socio): User
    {
        $dniRaw = (string) ($socio->dni ?? '');
        $dni = preg_replace('/\D+/', '', trim($dniRaw));

        $name = trim((string) ($socio->apynom ?? 'Socio'));
        $sid  = $socio->sid ? (string) $socio->sid : null;

        $defaults = [
            'is_admin' => 0,
            'is_professor' => 0,
            'account_status' => 'active',
            'name' => $name !== '' ? $name : 'Socio',
            'email' => null,
            'socio_id' => $sid,
            'socio_n'  => $sid,
            'barcode'  => $socio->barcode,
            'saldo'    => $socio->saldo ?? '0.00',
            'semaforo' => $socio->semaforo ?? 1,
            'estado_socio' => null,
            'avatar_path' => null,
            'foto_url' => null,
        ];

        // DNI inválido -> fallback por barcode/sid
        if ($dni === '' || strtolower(trim($dniRaw)) === 'dni') {
            $key = $socio->barcode ?: ('SID-' . (string)($socio->sid ?? $socio->id));

            $user = User::query()->where('barcode', $key)->first();

            if ($user) {
                $user->fill($defaults);
                $user->barcode = $key;
                $user->save();
                return $user;
            }

            $syntheticDni = 'SOCIO-' . (string) $socio->id;

            $defaults['dni'] = $syntheticDni;
            $defaults['barcode'] = $key;
            $defaults['password'] = Hash::make($syntheticDni);

            return User::create($defaults);
        }

        // Match por dni
        $user = User::query()->where('dni', $dni)->first();

        if (!$user) {
            $create = array_merge($defaults, [
                'dni' => $dni,
                'password' => Hash::make($dni),
            ]);

            return User::create($create);
        }

        // Existe: actualizar datos sin pisar password
        $user->fill($defaults);
        $user->dni = $dni;
        $user->save();

        return $user;
    }
}
