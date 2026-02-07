<?php

namespace App\Http\Controllers\Profesor;

use App\Http\Controllers\Controller;
use App\Models\SocioPadron;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SocioController extends Controller
{
    /**
     * GET /api/profesor/socios
     * Lista socios (SocioPadron) asignados al profesor autenticado.
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

        // 👇 Pivot guarda socio_id = socios_padron.id, así que leemos DESDE socios_padron
        $query = SocioPadron::query()
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
            ])
            ->orderBy('socios_padron.apynom')
            ->orderBy('socios_padron.dni');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('socios_padron.dni', 'like', "%{$q}%")
                  ->orWhere('socios_padron.sid', 'like', "%{$q}%")
                  ->orWhere('socios_padron.apynom', 'like', "%{$q}%");
            });
        }

        $result = $query->paginate($perPage)->appends($request->query());

        // Transform a “shape” compatible con tu frontend (similar a disponibles)
        $data = $result->getCollection()->map(function ($item) {
            $apellido = '';
            $nombre = '';

            $apynom = (string) ($item->apynom ?? '');
            if ($apynom !== '') {
                if (strpos($apynom, ',') !== false) {
                    [$apellido, $nombre] = array_map('trim', explode(',', $apynom, 2));
                } else {
                    $parts = array_map('trim', preg_split('/\s+/', $apynom));
                    $apellido = $parts[0] ?? '';
                    $nombre = implode(' ', array_slice($parts, 1));
                }
            }

            return [
                'id' => (int) $item->id,            // OJO: id de socios_padron
                'dni' => $item->dni,
                'socio_id' => $item->sid,           // compat
                'socio_n' => $item->sid,
                'apellido' => $apellido,
                'nombre' => $nombre,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'estado_socio' => null,
                'avatar_path' => null,
                'foto_url' => null,
                'type_label' => 'Socio',
            ];
        });

        $result->setCollection($data);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /api/profesor/socios/disponibles
     * SociosPadron NO asignados a ningún profesor.
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

        // Subquery: ids de socios_padron ya asignados
        $assignedIdsSub = DB::table('professor_socio')
            ->select('socio_id')
            ->distinct();

        $query = SocioPadron::query()
            ->whereNotIn('id', $assignedIdsSub)
            ->select([
                'id',
                'dni',
                'sid',
                'apynom',
                'barcode',
                'saldo',
                'semaforo',
                'hab_controles',
            ])
            ->orderBy('apynom')
            ->orderBy('dni');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('dni', 'like', "%{$q}%")
                  ->orWhere('sid', 'like', "%{$q}%")
                  ->orWhere('apynom', 'like', "%{$q}%");
            });
        }

        $padronResult = $query->paginate($perPage)->appends($request->query());

        $data = $padronResult->getCollection()->map(function (SocioPadron $item) {
            $apellido = '';
            $nombre = '';

            if (!empty($item->apynom)) {
                if (strpos($item->apynom, ',') !== false) {
                    [$apellido, $nombre] = array_map('trim', explode(',', $item->apynom, 2));
                } else {
                    $parts = array_map('trim', preg_split('/\s+/', $item->apynom));
                    $apellido = $parts[0] ?? '';
                    $nombre = implode(' ', array_slice($parts, 1));
                }
            }

            return [
                'id' => $item->id,
                'dni' => $item->dni,
                'socio_id' => $item->sid,
                'socio_n' => $item->sid,
                'apellido' => $apellido,
                'nombre' => $nombre,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'estado_socio' => null,
                'avatar_path' => null,
                'foto_url' => null,
                'type_label' => 'Socio',
            ];
        });

        $padronResult->setCollection($data);

        return response()->json(['success' => true, 'data' => $padronResult]);
    }

    /**
     * POST /api/profesor/socios/{socio}
     * {socio} = SocioPadron id
     */
    public function store(Request $request, $socioId): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $socio = SocioPadron::findOrFail($socioId);

        DB::table('professor_socio')->updateOrInsert(
            ['professor_id' => $profesor->id, 'socio_id' => $socio->id],
            ['assigned_by' => $profesor->id, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Socio asignado',
            'data' => [
                'professor_id' => $profesor->id,
                'socio_padron_id' => $socio->id,
                'dni' => $socio->dni,
                'sid' => $socio->sid,
            ],
        ], 201);
    }

    /**
     * DELETE /api/profesor/socios/{socio}
     * {socio} = SocioPadron id
     */
    public function destroy(Request $request, $socioId): JsonResponse
    {
        $profesor = $request->user();

        if (!$profesor) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        abort_unless((bool) $profesor->is_professor, 403, 'No autorizado: solo profesores');

        $socio = SocioPadron::findOrFail($socioId);

        $deleted = DB::table('professor_socio')
            ->where('professor_id', $profesor->id)
            ->where('socio_id', $socio->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => 'El socio no está asignado'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Socio desasignado',
            'data' => [
                'professor_id' => $profesor->id,
                'socio_padron_id' => $socio->id,
                'dni' => $socio->dni,
                'sid' => $socio->sid,
            ],
        ]);
    }
}
