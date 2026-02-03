<?php

namespace App\Http\Controllers\Profesor;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SocioController extends Controller
{
    /**
     * GET /api/profesor/socios
     * Lista los socios (usuarios API) asignados al profesor logueado
     * Requisitos:
     * - Profesor autenticado vía Sanctum (middleware auth:sanctum)
     * - Soporta paginación (per_page, page)
     * - Respuesta: { success: true, data: [...], meta: {...} }
     */
    public function index(Request $request): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor - Requisito: resolver professor_id desde auth()->user()
        abort_unless($profesor->is_professor, 403, 'No autorizado: solo profesores pueden acceder a esta ruta');

        $query = $profesor->sociosAsignados()
            ->where('user_type', UserType::API);

        // Buscar opcional por DNI, nombre, apellido
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $page = (int) $request->get('page', 1);
        
        $socios = $query->orderBy('apellido')->orderBy('nombre')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $socios->items(),
            'meta' => [
                'total' => $socios->total(),
                'per_page' => $socios->perPage(),
                'current_page' => $socios->currentPage(),
                'last_page' => $socios->lastPage(),
                'from' => $socios->firstItem(),
                'to' => $socios->lastItem(),
            ],
        ]);
    }

    /**
     * GET /api/profesor/socios/disponibles
     * Lista socios NO asignados al profesor logueado (disponibles para asignar)
     * Filtra por: user_type = 'api' y no estén ya asignados
     */
    public function disponibles(Request $request): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'No autorizado: solo profesores pueden acceder a esta ruta');

        // Obtener IDs de socios ya asignados
        $asignados = $profesor->sociosAsignados()->pluck('users.id')->all();

        // Query: socios (API users) NO asignados
        $query = User::query()
            ->where('user_type', UserType::API)
            ->whereNotIn('id', $asignados);

        // Buscar por DNI, nombre, apellido
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $page = (int) $request->get('page', 1);
        
        $socios = $query->orderBy('apellido')->orderBy('nombre')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $socios->items(),
            'meta' => [
                'total' => $socios->total(),
                'per_page' => $socios->perPage(),
                'current_page' => $socios->currentPage(),
                'last_page' => $socios->lastPage(),
                'from' => $socios->firstItem(),
                'to' => $socios->lastItem(),
            ],
        ]);
    }

    /**
     * POST /api/profesor/socios/{socio}
     * Auto-asignarse (profesor) un socio
     * El profesor NO puede enviar profesorId, siempre usa auth()->user()
     * Requisito: Validar que socio sea API, devolver 422 si ya está asignado o no es API
     */
    public function store(Request $request, User $socio): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'No autorizado: solo profesores pueden asignar socios');

        // Validar que el socio existe y es válido (user_type = 'api')
        abort_unless($socio->user_type === UserType::API, 422, 'El usuario debe ser un socio (tipo API)');

        // Validar que el socio no esté ya asignado
        if ($profesor->sociosAsignados()->where('socio_id', $socio->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'El socio ya está asignado a este profesor',
                'data' => null,
            ], 422);
        }

        // Asignar el socio al profesor
        $profesor->sociosAsignados()->attach($socio->id, [
            'assigned_by' => $profesor->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Socio asignado correctamente',
            'data' => [
                'profesor_id' => $profesor->id,
                'socio_id' => $socio->id,
                'socio' => $socio->only(['id', 'dni', 'nombre', 'apellido', 'name', 'email']),
            ],
        ], 201);
    }

    /**
     * DELETE /api/profesor/socios/{socio}
     * Auto-desasignarse (profesor) un socio
     * Requisito: Validar que el socio está asignado, devolver 404 si no existe
     */
    public function destroy(Request $request, User $socio): JsonResponse
    {
        $profesor = auth()->user();

        // Validar que sea profesor
        abort_unless($profesor->is_professor, 403, 'No autorizado: solo profesores pueden desasignar socios');

        // Validar que el socio existe y es válido (user_type = 'api')
        abort_unless($socio->user_type === UserType::API, 422, 'El usuario debe ser un socio (tipo API)');

        // Validar que el socio está asignado
        $assigned = $profesor->sociosAsignados()->where('socio_id', $socio->id)->exists();
        abort_unless($assigned, 404, 'El socio no está asignado a este profesor');

        // Desasignar el socio
        $profesor->sociosAsignados()->detach($socio->id);

        return response()->json([
            'success' => true,
            'message' => 'Socio desasignado correctamente',
            'data' => [
                'profesor_id' => $profesor->id,
                'socio_id' => $socio->id,
            ],
        ]);
    }
}
