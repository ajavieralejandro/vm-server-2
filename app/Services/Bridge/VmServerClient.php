<?php

namespace App\Services\Bridge;

use App\Exceptions\VmServerException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VmServerClient
{
    protected string $baseUrl;

    protected string $internalToken;

    protected int $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('VMSERVER_BASE_URL', ''), '/');
        $this->internalToken = (string) env('VMSERVER_INTERNAL_TOKEN', '');
        $this->timeoutSeconds = (int) env('VMSERVER_TIMEOUT_SECONDS', 8);
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
        if ($this->baseUrl === '' || $this->internalToken === '') {
            Log::error('VmServerClient misconfigured', [
                'base_url_present' => $this->baseUrl !== '',
                'internal_token_present' => $this->internalToken !== '',
            ]);

            throw new VmServerException('vmServer client is not properly configured');
        }

        $path = '/api/internal/padron/by-dni/' . urlencode($dni);

        try {
            Log::info('VmServerClient request', [
                'path' => $path,
                'timeout_seconds' => $this->timeoutSeconds,
            ]);

            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => $this->internalToken,
                ])
                ->get($path);

            $status = $response->status();
            $body = (string) $response->body();

            Log::info('VmServerClient response', [
                'status' => $status,
                'body_snippet' => substr($body, 0, 1000),
            ]);

            if ($status === 404) {
                return null;
            }

            if (!$response->successful()) {
                throw new VmServerException('vmServer error with status ' . $status);
            }

            $data = $response->json();

            if (!is_array($data)) {
                throw new VmServerException('vmServer returned an invalid response format');
            }

            return $data;
        } catch (VmServerException $e) {
            Log::error('VmServerClient VmServerException', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            Log::error('VmServerClient transport exception', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw new VmServerException('vmServer unavailable', 0, $e);
        }
    }
}
