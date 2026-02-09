<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SocioPadron;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfesorSocioController extends Controller
{
    /**
     * GET /api/admin/profesores
     * (queda igual, pero te recomiendo seleccionar columnas)
     */
    public function profesores(Request $request)
    {
        $q = User::query()
            ->where('is_professor', true)
            ->select(['id','name','email','dni','account_status','professor_since','created_at']);

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        return response()->json([
            'ok' => true,
            'data' => $q->orderBy('name')->paginate($perPage),
        ]);
    }

    /**
     * ✅ GET /api/admin/socios
     * socios = SocioPadron (NO users)
     * Query params:
     * - search (compat)
     * - q (recomendado)
     * - page, per_page
     */
    public function socios(Request $request)
    {
        $term = trim((string) ($request->get('q') ?: $request->get('search') ?: ''));

        $perPage = (int) $request->get('per_page', 20);
        $perPage = max(1, min(200, $perPage));

        $q = SocioPadron::query()
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

        if ($term !== '') {
            $q->where(function ($w) use ($term) {
                $w->where('dni', 'like', "%{$term}%")
                  ->orWhere('sid', 'like', "%{$term}%")
                  ->orWhere('apynom', 'like', "%{$term}%");
            });
        }

        $p = $q->paginate($perPage)->appends($request->query());

        // shape simple para el front (nombre/apellido)
        $data = $p->getCollection()->map(function ($item) {
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
                'id' => (int) $item->id,     // ✅ id de socios_padron
                'dni' => $item->dni,
                'sid' => $item->sid,
                'socio_id' => $item->sid,    // compat
                'socio_n' => $item->sid,
                'apellido' => $apellido,
                'nombre' => $nombre,
                'apynom' => $item->apynom,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'hab_controles' => $item->hab_controles,
                'type_label' => 'SocioPadron',
            ];
        });

        $p->setCollection($data);

        return response()->json([
            'ok' => true,
            'data' => $p,
        ]);
    }

    /**
     * ✅ GET /api/admin/profesores/{profesor}/socios
     * Devuelve SocioPadron asignados a ese profesor (leyendo pivot professor_socio)
     */
    public function sociosPorProfesor(Request $request, User $profesor)
    {
        abort_unless($profesor->is_professor, 404);

        $term = trim((string) ($request->get('q') ?: $request->get('search') ?: ''));

        $perPage = (int) $request->get('per_page', 50);
        $perPage = max(1, min(200, $perPage));

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
            ])
            ->orderBy('socios_padron.apynom')
            ->orderBy('socios_padron.dni');

        if ($term !== '') {
            $q->where(function ($w) use ($term) {
                $w->where('socios_padron.dni', 'like', "%{$term}%")
                  ->orWhere('socios_padron.sid', 'like', "%{$term}%")
                  ->orWhere('socios_padron.apynom', 'like', "%{$term}%");
            });
        }

        $p = $q->paginate($perPage)->appends($request->query());

        // mismo shape que socios()
        $data = $p->getCollection()->map(function ($item) {
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
                'id' => (int) $item->id,
                'dni' => $item->dni,
                'sid' => $item->sid,
                'socio_id' => $item->sid,
                'socio_n' => $item->sid,
                'apellido' => $apellido,
                'nombre' => $nombre,
                'apynom' => $item->apynom,
                'barcode' => $item->barcode,
                'saldo' => $item->saldo,
                'semaforo' => $item->semaforo,
                'hab_controles' => $item->hab_controles,
                'type_label' => 'SocioPadron',
            ];
        });

        $p->setCollection($data);

        return response()->json([
            'ok' => true,
            'data' => $p,
        ]);
    }

    /**
     * ✅ POST /api/admin/profesores/{profesor}/socios
     * body: { socio_ids: number[] }  // ids de socios_padron
     * SYNC total pivot professor_socio
     *
     * Nota: acá NO toco ProfessorStudentAssignment (porque eso requiere ids de users).
     */
    public function syncSocios(Request $request, User $profesor)
    {
        abort_unless($profesor->is_professor, 404);

        $data = $request->validate([
            'socio_ids'   => 'array',
            'socio_ids.*' => 'integer|exists:socios_padron,id',
        ]);

        $ids = $data['socio_ids'] ?? [];

        // Asegurar que existen en padron
        $validSocios = SocioPadron::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $adminId = auth()->id();

        // pivot professor_socio: (professor_id, socio_id)
        // guardamos assigned_by y timestamps si existen
        $pairs = [];
        foreach ($validSocios as $sid) {
            $pairs[$sid] = [
                'assigned_by' => $adminId,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        DB::transaction(function () use ($profesor, $pairs) {
            // Si tu relación está definida como belongsToMany(SocioPadron::class, 'professor_socio', ...)
            // esto sería ideal:
            // $profesor->sociosPadronAsignados()->sync($pairs);

            // Como no sabemos tu relación, hacemos sync directo en tabla:
            DB::table('professor_socio')->where('professor_id', $profesor->id)->delete();

            if (!empty($pairs)) {
                $rows = [];
                foreach ($pairs as $socioId => $pivot) {
                    $rows[] = array_merge($pivot, [
                        'professor_id' => $profesor->id,
                        'socio_id' => $socioId,
                    ]);
                }
                DB::table('professor_socio')->insert($rows);
            }
        });

        return response()->json([
            'ok' => true,
            'professor_id' => $profesor->id,
            'assigned_count' => count($validSocios),
            'assigned_ids' => $validSocios,
        ]);
    }
}
