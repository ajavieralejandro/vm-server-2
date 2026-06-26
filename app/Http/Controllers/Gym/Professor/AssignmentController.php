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
use App\Support\Gym\GymAssignmentAuthorization;
use App\Support\Gym\GymStudentEligibility;

class AssignmentController extends Controller
{
    /**
     * Endpoint: GET /api/profesor/socios/todos
     * Devuelve todos los socios visibles para el profesor autenticado, con flag de asignación.
     */
    public function allStudents(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $professorId = (int) auth()->id();
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(200, $perPage));
        $search = trim((string) $request->query('search', ''));

        $query = \App\Models\SocioPadron::query()
            ->select(['id', 'apynom', 'dni', 'hab_controles', 'acceso_full']);

        GymStudentEligibility::scopeEnabledPadron($query);

        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('apynom', 'like', "%{$search}%")
                  ->orWhere('sid', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('apynom')->orderBy('dni')->paginate($perPage)->appends($request->query());

        $asignados = \DB::table('professor_socio')
            ->where('professor_id', $professorId)
            ->pluck('socio_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $data = $paginator->getCollection()->map(function ($item) use ($asignados, $professorId) {
            $socioModel = SocioPadron::find($item->id);
            $enabled = $socioModel ? GymStudentEligibility::isPadronEnabled($socioModel) : false;
            $user = null;
            $psaId = null;

            if ($socioModel) {
                try {
                    $user = $this->ensureUserFromSocioPadronSafe($socioModel);
                    $psa = ProfessorStudentAssignment::query()
                        ->where('professor_id', $professorId)
                        ->where('student_id', $user->id)
                        ->first();
                    $psaId = $psa?->id;
                } catch (\Throwable) {
                    $user = null;
                }
            }

            $hasActiveAssignment = false;
            if ($user) {
                $hasActiveAssignment = \DB::table('daily_assignments as da')
                    ->join('professor_student_assignments as psa', 'psa.id', '=', 'da.professor_student_assignment_id')
                    ->where('psa.student_id', $user->id)
                    ->where('da.status', 'active')
                    ->exists();
            }

            $disabledReason = $socioModel ? GymStudentEligibility::padronDisabledReason($socioModel) : 'Socio no encontrado.';

            return [
                'id' => (int) $item->id,
                'socio_padron_id' => (int) $item->id,
                'user_id' => $user ? (int) $user->id : null,
                'psa_id' => $psaId ? (int) $psaId : null,
                'apynom' => $item->apynom,
                'name' => $item->apynom,
                'dni' => $item->dni,
                'enabled' => $enabled,
                'is_assigned_to_professor' => in_array((int) $item->id, $asignados, true),
                'has_active_assignment' => $hasActiveAssignment,
                'can_view' => true,
                'can_edit_progress' => false,
                'can_assign_routine' => $enabled && $user !== null,
                'can_assign_routine_reason' => $enabled
                    ? ($user === null ? 'No se pudo vincular el socio con un usuario de gimnasio.' : null)
                    : $disabledReason,
            ];
        });

        $paginator->setCollection($data);


        return response()->json([
            'ok' => true,
            'data' => $paginator,
        ]);
    }

    public function __construct(
        private AssignmentService $assignmentService
    ) {}

    /**
     * Obtener mis estudiantes asignados (desde professor_socio + socios_padron)
     * PHASE 1: Add metadata with user_id and psa_id to clarify ID usage.
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
                $socioPadronModel = SocioPadron::findOrFail($socio->id);
                $user = $this->ensureUserFromSocioPadronSafe($socioPadronModel);
                $psa = ProfessorStudentAssignment::query()->firstOrCreate(
                    ['professor_id' => $professorId, 'student_id' => (int) $user->id],
                    ['assigned_by' => $professorId, 'status' => 'active', 'start_date' => now()]
                );

                return [
                    // Backward compat
                    'id' => (int) $socio->id,
                    'professor_id' => $professorId,
                    'student_id' => (int) $socio->id,
                    'status' => 'active',

                    // PHASE 1: Clear ID mapping
                    '_meta' => [
                        'socio_padron_id' => (int) $socio->id,
                        'user_id' => (int) $user->id,
                        'psa_id' => (int) $psa->id,
                        'note' => 'Use user_id for studentTemplateAssignments(); use psa_id for template operations.',
                    ],

                    'student' => [
                        'id' => (int) $user->id,
                        'socio_padron_id' => (int) $socio->id,
                        'dni' => $socio->dni,
                        'name' => (string) ($socio->apynom ?? ''),
                        'email' => $user->email,
                        'user_type' => 'socio',
                        'type_label' => 'Socio',
                        'socio_id' => (string) ($socio->sid ?? null),
                        'socio_n' => (string) ($socio->sid ?? null),
                        'barcode' => $socio->barcode,
                        'saldo' => $socio->saldo,
                        'semaforo' => $socio->semaforo,
                        'hab_controles' => $socio->hab_controles,
                        'foto_url' => $user->foto_url,
                        'avatar_path' => $user->avatar_path,
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
            \Illuminate\Support\Facades\Log::error('myStudents error', [
                'professor_id' => auth()->id(),
                'message' => $e->getMessage(),
            ]);
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
                'professor_student_assignment_id' => 'nullable|integer|min:1',
                'student_id' => 'nullable|integer|exists:users,id',
                'socio_padron_id' => 'nullable|integer|exists:socios_padron,id',
                'daily_template_id' => 'required|integer|min:1',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'frequency' => 'nullable|array|min:1',
                'frequency.*' => 'integer|between:0,6',
                'professor_notes' => 'nullable|string|max:1000',
            ]);

            if (empty($validated['professor_student_assignment_id'])
                && empty($validated['student_id'])
                && empty($validated['socio_padron_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Debe indicar professor_student_assignment_id, student_id o socio_padron_id',
                ], 422);
            }

            if (!empty($validated['socio_padron_id'])) {
                $socio = SocioPadron::findOrFail((int) $validated['socio_padron_id']);
                if (!GymStudentEligibility::isPadronEnabled($socio)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El socio no está habilitado para recibir rutinas.',
                    ], 422);
                }
                $userSocio = $this->ensureUserFromSocioPadronSafe($socio);
                $validated['student_id'] = (int) $userSocio->id;
            }

            if (!empty($validated['student_id'])) {
                $student = User::findOrFail((int) $validated['student_id']);
                if (!GymStudentEligibility::isUserEnabled($student)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El socio no está habilitado para recibir rutinas.',
                    ], 422);
                }
            }

            // Validación manual consistente start/end
            if (!empty($validated['start_date']) && !empty($validated['end_date'])) {
                if (Carbon::parse($validated['end_date'])->lt(Carbon::parse($validated['start_date']))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'end_date debe ser >= start_date'
                    ], 422);
                }
            }

            $incoming = (int) ($validated['professor_student_assignment_id'] ?? 0);
            if ($incoming > 0) {
                $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);
            } else {
                $psa = $this->assignmentService->ensureProfessorStudentAssignment(
                    $professorId,
                    (int) $validated['student_id'],
                    $professorId
                );
                $psaId = (int) $psa->id;
            }

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
     * PHASE 1: Support both users.id and socios_padron.id. Use gym_daily_templates.
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

                if (!GymStudentEligibility::isPadronEnabled($socio)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El socio no está habilitado para recibir rutinas.'
                    ], 422);
                }

                $user = $this->ensureUserFromSocioPadronSafe($socio);
            } elseif (!GymStudentEligibility::isUserEnabled($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El socio no está habilitado para recibir rutinas.'
                ], 422);
            }

            $psa = DB::transaction(function () use ($professorId, $user) {
                return ProfessorStudentAssignment::query()->firstOrCreate(
                    ['professor_id' => $professorId, 'student_id' => (int) $user->id],
                    ['assigned_by' => $professorId, 'status' => 'active', 'start_date' => now()]
                );
            });

            $psaIds = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->where('student_id', (int) $user->id)
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->values()
                ->all();

            if (empty($psaIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => [
                        'student_user_id' => (int) $user->id,
                        'psa_ids_used' => [],
                        'count' => 0,
                    ],
                ]);
            }

            $query = DB::table('daily_assignments as da')
                ->whereIn('da.professor_student_assignment_id', $psaIds)
                ->where('da.status', 'active');

            if (!GymAssignmentAuthorization::isAdmin($request->user())) {
                $query->where(function ($q) use ($professorId, $psaIds) {
                    $q->where('da.assigned_by', $professorId)
                        ->orWhereIn('da.professor_student_assignment_id', $psaIds);
                });
            }

            $query->orderByDesc('da.start_date')
                ->select('da.*');

            // Attempt to join templates; gracefully skip if table missing
            if (\Illuminate\Support\Facades\Schema::hasTable('gym_daily_templates')) {
                $query->leftJoin('gym_daily_templates as dt', 'dt.id', '=', 'da.daily_template_id')
                    ->addSelect('dt.title as daily_template_title');
            }

            $rows = $query->get();

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
            \Illuminate\Support\Facades\Log::error('studentTemplateAssignments error', [
                'student_id' => $studentId,
                'professor_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener plantillas del alumno',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ver una asignación (daily_assignments)
     * PHASE 1: Use gym_daily_templates; fail gracefully if missing.
     */
    public function show(int $assignmentId): JsonResponse
    {
        try {
            $row = $this->findDailyAssignmentRow($assignmentId);

            if (!$row) {
                return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
            }

            GymAssignmentAuthorization::abortUnlessCanManageDailyAssignment(auth()->user(), $row);

            return response()->json(['success' => true, 'data' => $row]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('show error', ['assignment_id' => $assignmentId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al obtener asignación', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar asignación (daily_assignments)
     */
    public function updateAssignment(Request $request, int $assignmentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'end_date' => 'nullable|date',
                'frequency' => 'sometimes|array|min:1',
                'frequency.*' => 'integer|between:0,6',
                'professor_notes' => 'nullable|string|max:1000',
                'status' => 'sometimes|in:active,paused,completed,cancelled',
            ]);

            $row = $this->findDailyAssignmentRow($assignmentId);

            if (!$row) {
                return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
            }

            GymAssignmentAuthorization::abortUnlessCanManageDailyAssignment($request->user(), $row);

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

            $query = DB::table('daily_assignments')->where('id', $assignmentId)->select('daily_assignments.*');
            if (\Illuminate\Support\Facades\Schema::hasTable('gym_daily_templates')) {
                $query->leftJoin('gym_daily_templates as dt', 'dt.id', '=', 'daily_assignments.daily_template_id')
                    ->addSelect('dt.title as daily_template_title');
            }
            $fresh = $query->first();

            return response()->json(['success' => true, 'data' => $fresh]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('updateAssignment error', [
                'assignment_id' => $assignmentId,
                'professor_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Error al actualizar asignación', 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * ✅ Eliminar asignación (daily_assignments)
     */
    public function unassignTemplate(int $assignmentId): JsonResponse
    {
        $row = $this->findDailyAssignmentRow($assignmentId);

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada'], 404);
        }

        GymAssignmentAuthorization::abortUnlessCanManageDailyAssignment(auth()->user(), $row);

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

        $socio = SocioPadron::query()->find($socioPadronId);
        if (!$socio) {
            abort(404, 'Socio no encontrado en el padrón');
        }

        if (!GymStudentEligibility::isPadronEnabled($socio)) {
            abort(422, 'El socio no está habilitado para recibir rutinas.');
        }

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

    /**
     * PHASE 1: Create or update user from socios_padron with transaction safety.
     * Uses DB::transaction + lockForUpdate to prevent race conditions.
     */
    private function ensureUserFromSocioPadronSafe(SocioPadron $socio): User
    {
        return DB::transaction(function () use ($socio) {
            $lockedSocio = SocioPadron::lockForUpdate()->findOrFail($socio->id);
            $dniRaw = (string) ($lockedSocio->dni ?? '');
            $dni = preg_replace('/\D+/', '', trim($dniRaw));
            $name = trim((string) ($lockedSocio->apynom ?? 'Socio'));
            $sid  = $lockedSocio->sid ? (string) $lockedSocio->sid : null;

            $defaults = [
                'is_admin' => 0,
                'is_professor' => 0,
                'account_status' => 'active',
                'student_gym' => GymStudentEligibility::isPadronEnabled($lockedSocio),
                'student_gym_since' => GymStudentEligibility::isPadronEnabled($lockedSocio) ? now() : null,
                'name' => $name !== '' ? $name : 'Socio',
                'email' => null,
                'socio_id' => $sid,
                'socio_n'  => $sid,
                'barcode'  => $lockedSocio->barcode,
                'saldo'    => $lockedSocio->saldo ?? '0.00',
                'semaforo' => $lockedSocio->semaforo ?? 1,
                'estado_socio' => null,
                'avatar_path' => null,
                'foto_url' => null,
            ];

            if ($dni === '' || strtolower(trim($dniRaw)) === 'dni') {
                $key = $lockedSocio->barcode ?: ('SID-' . (string)($lockedSocio->sid ?? $lockedSocio->id));
                $user = User::query()->where('barcode', $key)->first();
                if ($user) {
                    // Preserve existing password, update other fields
                    unset($defaults['password']);
                    $user->fill($defaults);
                    $user->save();
                    \Illuminate\Support\Facades\Log::info('[USER] Updated from socio (barcode)', ['user_id' => $user->id, 'socio_id' => $lockedSocio->id]);
                    return $user;
                }
                $syntheticDni = 'SOCIO-' . (string) $lockedSocio->id;
                $create = $defaults;
                $create['dni'] = $syntheticDni;
                $create['barcode'] = $key;
                $create['password'] = Hash::make($syntheticDni);
                $newUser = User::create($create);
                \Illuminate\Support\Facades\Log::info('[USER] Created from socio (synthetic dni)', ['user_id' => $newUser->id, 'socio_id' => $lockedSocio->id]);
                return $newUser;
            }

            $user = User::query()->where('dni', $dni)->first();
            if (!$user) {
                $newUser = User::create(array_merge($defaults, [
                    'dni' => $dni,
                    'password' => Hash::make($dni),
                ]));
                \Illuminate\Support\Facades\Log::info('[USER] Created from socio (dni)', ['user_id' => $newUser->id, 'socio_id' => $lockedSocio->id]);
                return $newUser;
            }
            unset($defaults['password']);
            $user->fill($defaults);
            $user->dni = $dni;
            $user->save();
            \Illuminate\Support\Facades\Log::info('[USER] Updated from socio (dni)', ['user_id' => $user->id, 'socio_id' => $lockedSocio->id]);
            return $user;
        });
    }

    private function ensureUserFromSocioPadron(SocioPadron $socio): User
    {
        return $this->ensureUserFromSocioPadronSafe($socio);
    }

    private function findDailyAssignmentRow(int $assignmentId): ?object
    {
        $query = DB::table('daily_assignments as da')
            ->join('professor_student_assignments as psa', 'psa.id', '=', 'da.professor_student_assignment_id')
            ->where('da.id', $assignmentId)
            ->select(['da.*', 'psa.professor_id as psa_professor_id']);

        if (\Illuminate\Support\Facades\Schema::hasTable('gym_daily_templates')) {
            $query->leftJoin('gym_daily_templates as dt', 'dt.id', '=', 'da.daily_template_id')
                ->addSelect('dt.title as daily_template_title');
        }

        return $query->first();
    }
}
