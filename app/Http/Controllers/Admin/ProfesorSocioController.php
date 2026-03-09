<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SocioPadron;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class ProfesorSocioController extends Controller
{
    /**
     * GET /api/admin/profesores
     */
    public function profesores(Request $request): JsonResponse
    {
        $q = User::query()->where('is_professor', true);

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->orderBy('name')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /api/admin/socios
     * ✅ socios = socios_padron
     */
    public function socios(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $perPage = max(1, min(200, $perPage));
        $search = trim((string) $request->get('search', ''));

        $q = SocioPadron::query()->select([
            'id', 'dni', 'sid', 'apynom', 'barcode', 'saldo', 'semaforo', 'hab_controles'
        ]);

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('sid', 'like', "%{$search}%")
                  ->orWhere('apynom', 'like', "%{$search}%");
            });
        }

        $p = $q->orderBy('apynom')->orderBy('dni')->paginate($perPage)->appends($request->query());

        // shape igual al front
        $data = $p->getCollection()->map(function ($item) {
            return [
                'id' => (int) $item->id,         // id de socios_padron
                'dni' => $item->dni,
                'sid' => $item->sid,
                'apynom' => $item->apynom,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'hab_controles' => (bool) $item->hab_controles,
                'type_label' => 'Socio',
            ];
        });

        $p->setCollection($data);

        return response()->json([
            'ok' => true,
            'data' => $p,
        ]);
    }

    /**
     * GET /api/admin/profesores/{profesor}/socios
     * ✅ asignados desde socios_padron join pivot
     */
    public function sociosPorProfesor(Request $request, User $profesor): JsonResponse
    {
        abort_unless((bool) $profesor->is_professor, 404);

        $perPage = (int) $request->get('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $search = trim((string) $request->get('search', ''));

        $q = SocioPadron::query()
            ->join('professor_socio', 'professor_socio.socio_id', '=', 'socios_padron.id')
            ->where('professor_socio.professor_id', $profesor->id)
            ->select([
                'socios_padron.id',
                'socios_padron.dni',
                'socios_padron.sid',
                'socios_padron.apynom',
                'socios_padron.barcode',
                'socios_padron.saldo',
                'socios_padron.semaforo',
                'socios_padron.hab_controles',
            ]);

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('socios_padron.dni', 'like', "%{$search}%")
                  ->orWhere('socios_padron.sid', 'like', "%{$search}%")
                  ->orWhere('socios_padron.apynom', 'like', "%{$search}%");
            });
        }

        $p = $q->orderBy('socios_padron.apynom')->orderBy('socios_padron.dni')
            ->paginate($perPage)->appends($request->query());

        $data = $p->getCollection()->map(function ($item) {
            return [
                'id' => (int) $item->id,
                'dni' => $item->dni,
                'sid' => $item->sid,
                'apynom' => $item->apynom,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'hab_controles' => (bool) $item->hab_controles,
                'type_label' => 'Socio',
            ];
        });

        $p->setCollection($data);

        return response()->json([
            'ok' => true,
            'data' => $p,
        ]);
    }

    /**
     * POST /api/admin/professors/{profesor}/socios/assign
     * body: { socio_padron_id?: number, user_id?: number }
     * Agrega un socio al profesor sin quitar los demás (attach individual)
     */
    public function assignSocio(Request $request, User $profesor): JsonResponse
    {
        abort_unless((bool) $profesor->is_professor, 404);

        $data = $request->validate([
            'socio_padron_id' => 'nullable|integer|exists:socios_padron,id',
            'user_id'         => 'nullable|integer|exists:users,id',
        ]);

        if (empty($data['socio_padron_id']) && empty($data['user_id'])) {
            return response()->json(['message' => 'Se requiere socio_padron_id o user_id.'], 422);
        }

        if (!empty($data['socio_padron_id'])) {
            $profesor->sociosPadronAsignados()->syncWithoutDetaching([
                $data['socio_padron_id'] => [
                    'assigned_by' => auth()->id(),
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE|POST /api/admin/professors/{profesor}/socios/remove
     * body: { socio_padron_id?: number, user_id?: number }
     * Quita un socio del profesor (detach individual)
     */
    public function removeSocio(Request $request, User $profesor): JsonResponse
    {
        abort_unless((bool) $profesor->is_professor, 404);

        $data = $request->validate([
            'socio_padron_id' => 'nullable|integer|exists:socios_padron,id',
            'user_id'         => 'nullable|integer|exists:users,id',
        ]);

        if (!empty($data['socio_padron_id'])) {
            $profesor->sociosPadronAsignados()->detach($data['socio_padron_id']);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/admin/socios/make-professor
     * body: { user_id: number }
     * Asigna el rol de profesor a un usuario existente
     */
    public function makeProfessor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        if (!$user->is_professor) {
            $user->is_professor    = true;
            $user->professor_since = now();
            $user->save();
        }

        return response()->json(['ok' => true, 'professor' => $user->fresh()]);
    }

    /**
     * POST /api/admin/profesores/{profesor}/socios
     * body: { socio_ids: number[] }  // ✅ ids de socios_padron
     */
    public function syncSocios(Request $request, User $profesor): JsonResponse
    {
        abort_unless((bool) $profesor->is_professor, 404);

        $data = $request->validate([
            'socio_ids'   => 'array',
            'socio_ids.*' => 'integer|exists:socios_padron,id',
        ]);

        $ids = $data['socio_ids'] ?? [];
        $adminId = auth()->id();

        // armar payload sync con assigned_by
        $pairs = [];
        foreach ($ids as $sid) {
            $pairs[$sid] = [
                'assigned_by' => $adminId,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        DB::transaction(function () use ($profesor, $pairs) {
            // sync total pivot
            $profesor->sociosPadronAsignados()->sync($pairs);
        });

        return response()->json([
            'ok' => true,
            'assigned_count' => count($ids),
            'removed_count' => null,
        ]);
    }
}
