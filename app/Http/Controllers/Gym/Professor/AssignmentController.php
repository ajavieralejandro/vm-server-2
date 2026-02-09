<?php

namespace App\Http\Controllers\Gym\Professor;

use App\Http\Controllers\Controller;
use App\Services\Gym\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;


// Models
use App\Models\User;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;
use App\Models\Gym\AssignmentProgress;
use App\Models\SocioPadron; // ✅ si tu modelo está acá
use Illuminate\Pagination\LengthAwarePaginator;


class AssignmentController extends Controller
{
    public function __construct(
        private AssignmentService $assignmentService
    ) {}

    /**
     * Obtener mis estudiantes asignados
     */
   public function myStudents(Request $request): JsonResponse
{
    try {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(200, $perPage));
        $page    = (int) $request->query('page', 1);
        $search  = trim((string) $request->query('search', $request->query('q', '')));

        // SOCIOS asignados (professor_socio + socios_padron)
        $baseQuery = SocioPadron::query()
            ->join('professor_socio', 'professor_socio.socio_id', '=', 'socios_padron.id')
            ->where('professor_socio.professor_id', auth()->id())
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

        // Convertimos cada socio a "assignment" con estructura compatible:
        $data = $paginator->getCollection()->map(function ($socio) {
            return [
                'id' => (int) $socio->id, // id del socio_padron (pseudo id assignment)
                'professor_id' => (int) auth()->id(),
                'student_id' => (int) $socio->id, // pseudo
                'status' => 'active',
                'start_date' => null,
                'end_date' => null,
                'admin_notes' => null,
                'created_at' => null,
                'updated_at' => null,

                // el front usa assignment.student.xxx -> armamos un "student" compatible
                'student' => [
                    'id' => (int) $socio->id,
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

                // para que no rompa si espera template_assignments
                'template_assignments' => [],
            ];
        })->values();

        // Devolvemos paginator "clásico" igual que Laravel
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
            'message' => 'Error al obtener estudiantes',
            'error' => $e->getMessage()
        ], 500);
    }
}/**
 * GET /api/professor/students/{socioPadronId}/template-assignments
 * Devuelve plantillas del socio asignado al profesor (vía professor_socio),
 * creando User + ProfessorStudentAssignment si hace falta.
 */
public function studentTemplateAssignments(int $socioPadronId, Request $request): JsonResponse
{
    try {
        $professorId = auth()->id();

        // 1) Validar que el socio_padron esté asignado a este profesor
        $isAssigned = DB::table('professor_socio')
            ->where('professor_id', $professorId)
            ->where('socio_id', $socioPadronId)
            ->exists();

        if (!$isAssigned) {
            return response()->json([
                'message' => 'Alumno no asignado a este profesor'
            ], 403);
        }

        // 2) Traer socio_padron
        $socio = SocioPadron::findOrFail($socioPadronId);

        // 3) Resolver/crear user
        $user = $this->ensureUserFromSocioPadron($socio);

        // 4) Crear/obtener ProfessorStudentAssignment real (FK users.id)
        $psa = ProfessorStudentAssignment::firstOrCreate(
            [
                'professor_id' => $professorId,
                'student_id'   => $user->id,
            ],
            [
                'assigned_by' => $professorId,
                'status'      => 'active',
                'start_date'  => now(),
                'end_date'    => null,
                'admin_notes' => null,
            ]
        );

        // 5) Listar TemplateAssignments de ese PSA
        $items = TemplateAssignment::with(['dailyTemplate'])
            ->where('professor_student_assignment_id', $psa->id)
            ->orderByDesc('start_date')
            ->get();

        return response()->json($items);

    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Error al obtener plantillas del alumno',
            'error' => $e->getMessage(),
        ], 500);
    }
}


/**
 * ✅ Si el front manda el "id" que viene de myStudents() (socio_padron.id),
 * este método lo convierte a un id REAL de professor_student_assignments.
 *
 * Acepta:
 * - incomingId = professor_student_assignments.id (real)
 * - incomingId = socio_padron.id (porque myStudents lo devuelve como id y student_id)
 */private function resolveProfessorStudentAssignmentId(int $incomingId, int $professorId): int
{
    // Caso A) Vino un ID real de professor_student_assignments
    $psa = ProfessorStudentAssignment::query()
        ->where('id', $incomingId)
        ->first();

    if ($psa) {
        if ((int) $psa->professor_id !== $professorId) {
            abort(403, 'La asignación no pertenece a este profesor');
        }
        return (int) $psa->id;
    }

    // Caso B) Vino socio_padron.id (NO es users.id)
    $socioPadronId = $incomingId;

    // Validar que el socio esté asignado a este profesor
    $isAssigned = \DB::table('professor_socio')
        ->where('professor_id', $professorId)
        ->where('socio_id', $socioPadronId)
        ->exists();

    if (!$isAssigned) {
        abort(403, 'El socio no está asignado a este profesor');
    }

    // Buscar socio en padron
    $socio = SocioPadron::query()->findOrFail($socioPadronId);

    // ✅ Crear/obtener User socio (para cumplir FK users.id)
    $userSocio = $this->ensureUserFromSocioPadron($socio);

    // Crear/obtener professor_student_assignment REAL usando users.id
    $psa = ProfessorStudentAssignment::query()->firstOrCreate(
        [
            'professor_id' => $professorId,
            'student_id'   => (int) $userSocio->id,   // ✅ FK OK
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
}private function ensureUserFromSocioPadron(SocioPadron $socio): User
{
    $dni = trim((string) $socio->dni);
    $name = trim((string) ($socio->apynom ?? 'Socio'));

    // ⚠️ user_type: NO pongas "socio" si tu Enum no lo soporta.
    // Usá el valor válido de tu app. Ejemplos típicos: 'local' o 'api'.
    $validUserType = 'local'; // <-- AJUSTÁ si tu enum usa otro backing value

    // password = dni (si no hay dni válido, generamos uno)
    $plainPassword = ($dni !== '' && strtolower($dni) !== 'dni') ? $dni : ('SOCIO-' . (string)$socio->id);

    $defaults = [
        'user_type' => $validUserType,
        'is_admin' => 0,
        'is_professor' => 0,
        'account_status' => 'active',
        'name' => $name !== '' ? $name : 'Socio',
        'email' => null,
        'password' => Hash::make($plainPassword),

        'socio_id' => $socio->sid ? (string)$socio->sid : null,
        'socio_n'  => $socio->sid ? (string)$socio->sid : null,
        'barcode'  => $socio->barcode,
        'saldo'    => $socio->saldo ?? '0.00',
        'semaforo' => $socio->semaforo ?? 1,
        'estado_socio' => null,
        'avatar_path' => null,
        'foto_url' => null,
    ];

    // DNI inválido / basura -> resolvemos por barcode o por socio_id
    if ($dni === '' || strtolower($dni) === 'dni') {
        $keyBarcode = $socio->barcode ?: null;

        $user = null;
        if ($keyBarcode) {
            $user = User::query()->where('barcode', $keyBarcode)->first();
        }

        if (!$user && $socio->sid) {
            $user = User::query()->where('socio_id', (string)$socio->sid)->first();
        }

        if ($user) {
            $user->fill($defaults);
            $user->save();
            return $user;
        }

        // Creamos un dni sintético para cumplir constraints si hace falta
        $defaults['dni'] = 'SOCIO-' . (string)$socio->id;
        return User::create($defaults);
    }

    // Caso normal: match por dni
    $user = User::firstOrCreate(['dni' => $dni], array_merge($defaults, ['dni' => $dni]));
    $user->fill($defaults);
    $user->dni = $dni;
    $user->save();

    return $user;
}

    public function updateAssignment(Request $request, $assignmentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'end_date' => 'nullable|date|after:start_date',
                'frequency' => 'sometimes|array|min:1',
                'frequency.*' => 'integer|between:0,6',
                'professor_notes' => 'nullable|string|max:1000',
                'status' => 'sometimes|in:active,paused,completed,cancelled'
            ]);

            $assignment = TemplateAssignment::findOrFail($assignmentId);

            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para modificar esta asignación'
                ], 403);
            }

            $assignment->update($validated);

            return response()->json([
                'message' => 'Asignación actualizada exitosamente',
                'data' => $assignment->fresh(['dailyTemplate', 'professorStudentAssignment.student'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al actualizar asignación',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Desasignar/Eliminar plantilla de estudiante
     */
    public function unassignTemplate($assignmentId): JsonResponse
    {
        try {
            $assignment = TemplateAssignment::with(['professorStudentAssignment.student', 'dailyTemplate'])
                ->findOrFail($assignmentId);

            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para eliminar esta asignación'
                ], 403);
            }

            $studentName = $assignment->professorStudentAssignment->student->name ?? 'Alumno';
            $templateTitle = $assignment->dailyTemplate->title ?? 'Plantilla';

            $assignment->delete();

            return response()->json([
                'message' => "Plantilla '{$templateTitle}' desasignada exitosamente de {$studentName}",
                'student_name' => $studentName,
                'template_title' => $templateTitle
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al desasignar plantilla',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Agregar feedback a una sesión completada
     */
    public function addFeedback(Request $request, $progressId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'professor_feedback' => 'required|string|max:1000',
                'overall_rating' => 'nullable|numeric|between:1,5'
            ]);

            $progress = $this->assignmentService->addProfessorFeedback(
                $progressId,
                $validated['professor_feedback'],
                $validated['overall_rating'] ?? null
            );

            return response()->json([
                'message' => 'Feedback agregado exitosamente',
                'data' => $progress
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al agregar feedback',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Obtener progreso de un estudiante específico
     */
    public function studentProgress($studentId, Request $request): JsonResponse
    {
        try {
            $assignment = ProfessorStudentAssignment::query()
                ->where('professor_id', auth()->id())
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->first();

            if (!$assignment) {
                return response()->json([
                    'message' => 'Estudiante no asignado o inactivo'
                ], 403);
            }

            $assignments = $this->assignmentService->getStudentTemplateAssignments(
                (int) $studentId,
                $this->buildFilters($request)
            );

            return response()->json($assignments);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener progreso del estudiante',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas del profesor
     */
    public function myStats(): JsonResponse
    {
        try {
            $stats = $this->assignmentService->getProfessorStats(auth()->id());
            return response()->json($stats);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sesiones pendientes de hoy
     */
    public function todaySessions(): JsonResponse
    {
        try {
            $today = now()->toDateString();

            $sessions = AssignmentProgress::with([
                'templateAssignment.dailyTemplate',
                'templateAssignment.professorStudentAssignment.student'
            ])
                ->whereHas('templateAssignment.professorStudentAssignment', function ($query) {
                    $query->where('professor_id', auth()->id());
                })
                ->where('scheduled_date', $today)
                ->orderBy('status')
                ->get();

            return response()->json([
                'date' => $today,
                'sessions' => $sessions,
                'total' => $sessions->count(),
                'completed' => $sessions->where('status', 'completed')->count(),
                'pending' => $sessions->where('status', 'pending')->count()
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener sesiones de hoy',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener calendario semanal
     */
    public function weeklyCalendar(Request $request): JsonResponse
    {
        try {
            $startDate = $request->has('start_date')
                ? Carbon::parse($request->input('start_date'))
                : now()->startOfWeek();

            $endDate = $startDate->copy()->endOfWeek();

            $sessions = AssignmentProgress::with([
                'templateAssignment.dailyTemplate',
                'templateAssignment.professorStudentAssignment.student'
            ])
                ->whereHas('templateAssignment.professorStudentAssignment', function ($query) {
                    $query->where('professor_id', auth()->id());
                })
                ->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('scheduled_date')
                ->get()
                ->groupBy('scheduled_date');

            return response()->json([
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'sessions_by_date' => $sessions
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener calendario semanal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Construir filtros desde request
     */
    private function buildFilters(Request $request): array
    {
        return array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'active_only' => $request->boolean('active_only') ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
