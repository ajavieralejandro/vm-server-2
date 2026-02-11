<?php

namespace App\Services\Bridge;

use App\Exceptions\VmServerAuthException;
use App\Exceptions\VmServerException;
use App\Exceptions\VmServerUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VmServerClient
{
    protected string $baseUrl;

    protected string $internalToken;

    protected int $timeoutSeconds;

    public function __construct()
    {
        $config = (array) config('services.vmserver', []);

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->internalToken = (string) ($config['internal_token'] ?? '');
        $this->timeoutSeconds = (int) ($config['timeout'] ?? 8);
    }

    /**
     * Fetch padrón data by DNI from vmServer internal endpoint.
     *
     * @param string $dni Normalized DNI (digits only)
     * @return array|null
     *
     * @throws VmServerException
     */
    public function getPadronByDni(string $dni): ?array
    {
        $start = microtime(true);
        $status = null;

        if ($this->baseUrl === '' || $this->internalToken === '') {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::error('VmServerClient misconfigured', [
                'dni' => $dni,
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_type' => 'misconfigured',
            ]);

            throw new VmServerUnavailableException('vmServer client is not properly configured');
        }

        $path = '/api/internal/padron/by-dni/' . urlencode($dni);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => $this->internalToken,
                ])
                ->get($path);

            $status = $response->status();
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            if ($status === 404) {
                Log::info('VmServerClient padron not found', [
                    'dni' => $dni,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'error_type' => 'not_found',
                ]);

                return null;
            }

            if ($status === 401 || $status === 403) {
                Log::warning('VmServerClient auth error', [
                    'dni' => $dni,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'error_type' => 'auth',
                ]);

                throw new VmServerAuthException('vmServer authentication failed with status ' . $status);
            }

            if (!$response->successful()) {
                Log::error('VmServerClient upstream error', [
                    'dni' => $dni,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'error_type' => 'upstream_error',
                ]);

                throw new VmServerException('vmServer error with status ' . $status);
            }

            $data = $response->json();

            if (!is_array($data)) {
                Log::error('VmServerClient invalid json', [
                    'dni' => $dni,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'error_type' => 'invalid_json',
                ]);

                throw new VmServerException('vmServer returned an invalid response format');
            }

            Log::info('VmServerClient padron ok', [
                'dni' => $dni,
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_type' => 'ok',
            ]);

            return $data;
        } catch (VmServerAuthException|VmServerException|VmServerUnavailableException $e) {
            // Already logged in the specific branch above.
            throw $e;
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            Log::error('VmServerClient network/unexpected error', [
                'dni' => $dni,
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_type' => 'network',
            ]);

            throw new VmServerUnavailableException('vmServer unavailable', 0, $e);
        }
    }
}
