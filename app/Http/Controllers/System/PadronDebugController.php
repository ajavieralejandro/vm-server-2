<?php

namespace App\Http\Controllers\System;

use App\Exceptions\VmServerException;
use App\Http\Controllers\Controller;
use App\Services\Bridge\PadronResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PadronDebugController extends Controller
{
    /**
     * Debug endpoint to inspect padrón data for the authenticated gym user.
     *
     * Example cURL (replace placeholders):
     *
     * curl -X GET \
     *   -H "Accept: application/json" \
     *   -H "Authorization: Bearer {SANCTUM_TOKEN}" \
     *   https://your-api-domain.test/api/sys/padron/me
     */
    public function me(Request $request, PadronResolver $resolver): JsonResponse
    {
        $user = $request->user();

        try {
            $normalizedDni = PadronResolver::normalizeDni($user->dni ?? null);
            $cacheKey = $normalizedDni !== ''
                ? PadronResolver::cacheKeyForDni($normalizedDni)
                : null;

            $padron = $resolver->resolveForGymUser($user);

            $cached = $cacheKey !== null ? Cache::has($cacheKey) : false;

            return response()->json([
                'ok' => true,
                'gym_user' => [
                    'id' => $user->id,
                    'dni' => $user->dni,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'padron' => $padron,
                'cached' => $cached,
            ]);
        } catch (VmServerException $e) {
            Log::error('PadronDebugController vmServer unavailable', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'vmServer unavailable',
            ], 502);
        }
    }
}
