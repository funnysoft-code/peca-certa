<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24\Concerns;

use Illuminate\Http\Client\RequestException;
use Throwable;

trait SoftFailsPartsLink24Http
{
    /**
     * Run a tool body and convert HTTP/transport failures into JSON soft errors
     * so IdentifyAgentJob (Tries=1) does not die with an empty failed run.
     *
     * @param  callable(): string  $callback
     */
    private function withSoftHttp(callable $callback): string
    {
        try {
            return $callback();
        } catch (RequestException $exception) {
            return json_encode($this->httpErrorPayload($exception), JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return json_encode([
                'ok' => false,
                'error' => 'http_error',
                'status' => null,
                'body' => mb_substr($exception->getMessage(), 0, 500),
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @return array{ok: false, error: string, status: int, body: string}
     */
    private function httpErrorPayload(RequestException $exception): array
    {
        $response = $exception->response;
        $status = $response->status();
        $effectiveUri = $response->effectiveUri();
        $url = $effectiveUri !== null ? (string) $effectiveUri : '';
        $body = mb_substr($response->body(), 0, 500);
        $message = $exception->getMessage();

        if ($this->isPartsLink24AuthFailure($status, $url, $message, $body)) {
            return [
                'ok' => false,
                'error' => 'pl24_auth_error',
                'status' => $status,
                'body' => 'PartsLink24 authentication was rejected. Operator: catálogo OE indisponível; contact support (credentials/network).',
            ];
        }

        return [
            'ok' => false,
            'error' => 'http_error',
            'status' => $status,
            'body' => $body,
        ];
    }

    private function isPartsLink24AuthFailure(int $status, string $url, string $message, string $body): bool
    {
        if ($status !== 401 && $status !== 403) {
            return false;
        }

        $haystack = mb_strtolower($url.' '.$message.' '.$body);

        // Login 403 often has empty message body; treat any 401/403 against PL24
        // auth paths (or generic Forbidden during catalog auth) as auth failure.
        return str_contains($haystack, '/login')
            || str_contains($haystack, 'pl24-appgtw')
            || str_contains($haystack, 'partslink24')
            || str_contains($haystack, 'authorize')
            || str_contains($haystack, 'forbidden')
            || $status === 403;
    }
}
