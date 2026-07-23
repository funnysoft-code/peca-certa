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

        return [
            'ok' => false,
            'error' => 'http_error',
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ];
    }
}
