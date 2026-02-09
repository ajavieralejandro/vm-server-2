<?php

namespace App\Http\Controllers\Gym\Professor;

use App\Http\Controllers\Controller;
use App\Services\Gym\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

use App\Models\User;
use App\Models\SocioPadron;
use App\Models\Gym\ProfessorStudentAssignment;

class AssignmentController extends Controller
{
    public function __construct(
        private AssignmentService $assignmentService
    ) {}

    /**
     * Obtener mis estudiantes asignados (desde professor_socio + socios_padron)
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
                    'id' => (int) $socio->id, // pseudo id
                    'professor_id' => $professorId,
                    'student_id' => (int) $socio->id, // pseudo
                    'status' => 'active',

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

                    'template_assignments' => [],
                ];
            })->values();

            $out = new LengthAwarePaginator(
                $data,
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                ['path' => $request->url(), 'query' => $request->query()]
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
     * Asignar plantilla a estudiante
     */
    public function assignTemplate(Request $request): JsonResponse
    {
        try {
            $professorId = (int) auth()->id();

            $validated = $request->validate([
                'professor_student_assignment_id' => 'required|integer|min:1',
                'daily_template_id' => 'required|integer|min:1',
                'start_date' => 'nullable|date',
                // FIX: si no viene start_date, end_date no debería validar contra algo inexistente
                'end_date' => 'nullable|date',
                'frequency' => 'nullable|array|min:1',
                'frequency.*' => 'integer|between:0,6',
                'professor_notes' => 'nullable|string|max:1000',
            ]);

            // Validación manual consistente start/end
            if (!empty($validated['start_date']) && !empty($validated['end_date'])) {
                if (Carbon::parse($validated['end_date'])->lt(Carbon::parse($validated['start_date']))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'end_date debe ser >= start_date'
                    ], 422);
                }
            }

            $incoming = (int) $validated['professor_student_assignment_id'];
            $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);

            $psa = ProfessorStudentAssignment::query()
                ->where('id', $psaId)
                ->where('professor_id', $professorId)
                ->firstOrFail();

            if ($psa->status !== 'active') {
                $psa->status = 'active';
                $psa->end_date = null;
                $psa->save();
            }

            $payload = $validated;
            $payload['professor_student_assignment_id'] = (int) $psa->id;
            $payload['assigned_by'] = $professorId;
            $payload['student_id'] = (int) $psa->student_id;

            $assignment = $this->assignmentService->assignTemplateToStudent($payload);

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => $assignment,
            ], 201);

        } catch (\Throwable $e) {
            $msg = (string) $e->getMessage();

            // Fallback directo
            if (str_contains($msg, 'Asignación profesor-estudiante no válida') || str_contains($msg, 'no válida o inactiva')) {
                $professorId = (int) auth()->id();

                $incoming = (int) $request->input('professor_student_assignment_id');
                $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);

                $start = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : now()->startOfDay();
                $end   = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : null;

                if ($end && $end->lt($start)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'end_date debe ser >= start_date'
                    ], 422);
                }

                $id = DB::table('daily_assignments')->insertGetId([
                    'professor_student_assignment_id' => $psaId,
                    'daily_template_id' => (int) $request->input('daily_template_id'),
                    'start_date' => $start,
                    'end_date' => $end,
                    'frequency' => $request->has('frequency') ? json_encode($request->input('frequency')) : null,
                    'professor_notes' => $request->input('professor_notes'),
                    'status' => 'active',
                    'assigned_by' => $professorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('daily_assignments')->where('id', $id)->first();

                return response()->json([
                    'success' => true,
                    'message' => 'ok',
                    'data' => $row,
                    'warning' => 'Fallback: se creó directo en daily_assignments porque el service rechazó el PSA.',
                ], 201);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al asignar plantilla',
                'error' => $msg,
            ], 422);
        }
    }

    /**
     * Traer plantillas asignadas del alumno (por daily_assignments)
     */
    public function studentTemplateAssignments(Request $request, int $studentId): JsonResponse
    {
        try {
            $professorId = (int) auth()->id();

            $user = User::find($studentId);

            if (!$user) {
                $socio = SocioPadron::find($studentId);
                if (!$socio) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Alumno no encontrado (ni user ni socio padron)'
                    ], 404);
                }

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

                $user = $this->ensureUserFromSocioPadron($socio);
            }

            ProfessorStudentAssignment::query()->firstOrCreate(
                ['professor_id' => $professorId, 'student_id' => (int) $user->id],
                ['assigned_by' => $professorId, 'status' => 'active', 'start_date' => now()]
            );

            $psaIds = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->where('student_id', (int) $user->id)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->values()
                ->all();

            $rows = DB::table('daily_assignments as da')
                ->leftJoin('daily_templates as dt', 'dt.id', '=', 'da.daily_template_id')
                ->whereIn('da.professor_student_assignment_id', $psaIds)
                ->orderByDesc('da.start_date')
                ->select(['da.*', DB::raw('dt.title as daily_template_title')])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $rows,
                'meta' => [
                    'student_user_id' => (int) $user->id,
                    'psa_ids_used' => $psaIds,
                    'count' => $rows->count(),
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
     * ✅ Ver una asignación (daily_assignments)
     */
    public function show(int $assignmentId): JsonResponse
    {
        $professorId = (int) auth()->id();

        $row = DB::table('daily_assignments as da')
            ->join('professor_student_assignments as psa', 'psa.id', '=', 'da.professor_student_assignment_id')
            ->leftJoin('daily_templates as dt', 'dt.id', '=', 'da.daily_template_id')
            ->where('da.id', $assignmentId)
            ->where('psa.professor_id', $professorId)
            ->select(['da.*', DB::raw('dt.title as daily_template_title')])
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        return response()->json(['success' => true, 'data' => $row]);
    }

    /**
     * ✅ Actualizar asignación (daily_assignments)
     */
    public function updateAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $professorId = (int) auth()->id();

        $validated = $request->validate([
            'end_date' => 'nullable|date',
            'frequency' => 'sometimes|array|min:1',
            'frequency.*' => 'integer|between:0,6',
            'professor_notes' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:active,paused,completed,cancelled',
        ]);

        $row = DB::table('daily_assignments as da')
            ->join('professor_student_assignments as psa', 'psa.id', '=', 'da.professor_student_assignment_id')
            ->where('da.id', $assignmentId)
            ->where('psa.professor_id', $professorId)
            ->select(['da.*'])
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        // Validación start/end contra lo que ya existe
        $start = Carbon::parse($row->start_date);
        if (!empty($validated['end_date'])) {
            $end = Carbon::parse($validated['end_date']);
            if ($end->lt($start)) {
                return response()->json(['success' => false, 'message' => 'end_date debe ser >= start_date'], 422);
            }
        }

        $update = $validated;

        if (array_key_exists('frequency', $update)) {
            $update['frequency'] = json_encode($update['frequency']);
        }

        $update['updated_at'] = now();

        DB::table('daily_assignments')->where('id', $assignmentId)->update($update);

        $fresh = DB::table('daily_assignments')->where('id', $assignmentId)->first();

        return response()->json(['success' => true, 'data' => $fresh]);
    }

    /**
     * ✅ Eliminar asignación (daily_assignments)
     */
    public function unassignTemplate(int $assignmentId): JsonResponse
    {
        $professorId = (int) auth()->id();

        $row = DB::table('daily_assignments as da')
            ->join('professor_student_assignments as psa', 'psa.id', '=', 'da.professor_student_assignment_id')
            ->where('da.id', $assignmentId)
            ->where('psa.professor_id', $professorId)
            ->select(['da.id'])
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        DB::table('daily_assignments')->where('id', $assignmentId)->delete();

        return response()->json(['success' => true, 'message' => 'Asignación eliminada']);
    }

    /**
     * ✅ Resuelve incoming (PSA real o socio_padron.id) => PSA real
     */
    private function resolveProfessorStudentAssignmentId(int $incomingId, int $professorId): int
    {
        $psa = ProfessorStudentAssignment::query()->where('id', $incomingId)->first();
        if ($psa) {
            if ((int) $psa->professor_id !== $professorId) {
                abort(403, 'La asignación no pertenece a este profesor');
            }
            return (int) $psa->id;
        }

        $socioPadronId = $incomingId;

        $isAssigned = DB::table('professor_socio')
            ->where('professor_id', $professorId)
            ->where('socio_id', $socioPadronId)
            ->exists();

        if (!$isAssigned) {
            abort(403, 'El socio no está asignado a este profesor');
        }

        $socio = SocioPadron::query()->findOrFail($socioPadronId);
        $userSocio = $this->ensureUserFromSocioPadron($socio);

        $psa = ProfessorStudentAssignment::query()->firstOrCreate(
            ['professor_id' => $professorId, 'student_id' => (int) $userSocio->id],
            ['assigned_by' => $professorId, 'status' => 'active', 'start_date' => now()]
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

            $create = $defaults;
            $create['dni'] = $syntheticDni;
            $create['barcode'] = $key;
            $create['password'] = Hash::make($syntheticDni);

            return User::create($create);
        }

        $user = User::query()->where('dni', $dni)->first();
        if (!$user) {
            return User::create(array_merge($defaults, [
                'dni' => $dni,
                'password' => Hash::make($dni),
            ]));
        }

        $user->fill($defaults);
        $user->dni = $dni;
        $user->save();

        return $user;
    }
}
