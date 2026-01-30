<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class AdminUsersController extends Controller
{
    public function index(Request $request)
    {
        $base = rtrim((string) config('services.vmserver.base_url'), '/');
        $path = (string) config('services.vmserver.admin_users_path', '/api/admin/users');
        $timeout = (int) config('services.vmserver.timeout', 10);
        $token = (string) config('services.vmserver.token');

        if (!$base) {
            return response()->json([
                'success' => false,
                'message' => 'VMSERVER_BASE_URL no configurado',
            ], 500);
        }

        $query = $request->query();

        $http = Http::timeout($timeout);
        if (!empty($token)) {
            $http = $http->withToken($token);
        }

        $resp = $http->get($base . $path, $query);

        if (!$resp->ok()) {
            return response()->json([
                'success' => false,
                'message' => 'Error consultando vmServer (API de usuarios)',
                'status'  => $resp->status(),
                'upstream_body' => $resp->json() ?? $resp->body(),
            ], 502);
        }

        return response()->json($resp->json());
    }
}
