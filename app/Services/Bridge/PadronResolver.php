<?php

namespace App\Services\Bridge;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PadronResolver
{
    protected VmServerClient $client;

    public function __construct(VmServerClient $client)
    {
        $this->client = $client;
    }

    /**
     * Normalize a DNI string to digits-only.
     */
    public static function normalizeDni(?string $dni): string
    {
        if ($dni === null) {
            return '';
        }

        return preg_replace('/\D+/', '', (string) $dni) ?? '';
    }

    /**
     * Build the cache key for a normalized DNI.
     */
    public static function cacheKeyForDni(string $normalizedDni): string
    {
        return 'padron:dni:' . $normalizedDni;
    }

    /**
     * Resolve padrón data for a given gym user.
     *
     * @return array|null
     */
    public function resolveForGymUser(User $user): ?array
    {
        $normalizedDni = self::normalizeDni($user->dni ?? null);

        if ($normalizedDni === '') {
            return null;
        }

        $cacheKey = self::cacheKeyForDni($normalizedDni);

        return Cache::remember($cacheKey, 600, function () use ($normalizedDni) {
            $padron = $this->client->getPadronByDni($normalizedDni);

            return $padron ?? null;
        });
    }
}
