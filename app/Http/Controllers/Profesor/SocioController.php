<?php

namespace App\Http\Controllers\Profesor;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SocioController extends Controller
{
    /**
     * GET /api/profesor/socios
     * Lista socios asignados al profesor autenticado.
     * Query params: q (búsqueda), per_page (default 50, max 200)
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function index(Request $request): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $q = trim((string) $request->query('q', ''));

        $query = $profesor->sociosAsignados()
            ->where('users.user_type', 'API')
            ->select([
                'users.id',
                'users.dni',
                'users.socio_id',
                'users.socio_n',
                'users.apellido',
                'users.nombre',
                'users.barcode',
                'users.saldo',
                'users.semaforo',
                'users.estado_socio',
                'users.avatar_path',
            ])
            ->orderBy('users.apellido')
            ->orderBy('users.nombre');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('users.dni', 'like', "%{$q}%")
                    ->orWhere('users.socio_id', 'like', "%{$q}%")
                    ->orWhere('users.socio_n', 'like', "%{$q}%")
                    ->orWhere('users.apellido', 'like', "%{$q}%")
                    ->orWhere('users.nombre', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(users.apellido, ' ', users.nombre) LIKE ?", ["%{$q}%"]);
            });
        }

        $result = $query->paginate($perPage)->appends($request->query());

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/profesor/socios/disponibles
     * Lista socios (API users) NO asignados a este profesor.
     * Query params: q (búsqueda), per_page (default 50, max 200)
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function disponibles(Request $request): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $q = trim((string) $request->query('q', ''));

        // Subquery (mejor que pluck -> no trae IDs a PHP)
        $assignedIdsSub = $profesor->sociosAsignados()->select('users.id');

        $query = User::query()
            ->where('user_type', 'API')
            ->whereNotIn('id', $assignedIdsSub)
            ->select([
                'id',
                'dni',
                'socio_id',
                'socio_n',
                'apellido',
                'nombre',
                'barcode',
                'saldo',
                'semaforo',
                'estado_socio',
                'avatar_path',
            ])
            ->orderBy('apellido')
            ->orderBy('nombre');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('dni', 'like', "%{$q}%")
                    ->orWhere('socio_id', 'like', "%{$q}%")
                    ->orWhere('socio_n', 'like', "%{$q}%")
                    ->orWhere('apellido', 'like', "%{$q}%")
                    ->orWhere('nombre', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(apellido, ' ', nombre) LIKE ?", ["%{$q}%"]);
            });
        }

        $result = $query->paginate($perPage)->appends($request->query());

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * POST /api/profesor/socios/{socio}
     * Asigna un socio (API user) al profesor autenticado.
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function store(Request $request, User $socio): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        // Validar que el socio sea tipo API
        if ($socio->user_type !== 'API') {
            return response()->json([
                'success' => false,
                'message' => 'El usuario debe ser un socio (tipo API)',
            ], 422);
        }

        // Validar que no esté ya asignado (ojo: users.id, no pivot socio_id ambiguo)
        $already = $profesor->sociosAsignados()->where('users.id', $socio->id)->exists();
        if ($already) {
            return response()->json([
                'success' => false,
                'message' => 'El socio ya está asignado',
            ], 409);
        }

        $profesor->sociosAsignados()->attach($socio->id, ['assigned_by' => $profesor->id]);

        return response()->json(['success' => true, 'data' => $socio], 201);
    }

    /**
     * DELETE /api/profesor/socios/{socio}
     * Desasigna un socio del profesor autenticado.
     * Requisito: auth:sanctum + profesor (is_professor=true)
     */
    public function destroy(Request $request, User $socio): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        if ($socio->user_type !== 'API') {
            return response()->json([
                'success' => false,
                'message' => 'El usuario debe ser un socio (tipo API)',
            ], 422);
        }

        $assigned = $profesor->sociosAsignados()->where('users.id', $socio->id)->exists();
        abort_unless($assigned, 404, 'El socio no está asignado');

        $profesor->sociosAsignados()->detach($socio->id);

        return response()->json(['success' => true]);
    }
}
