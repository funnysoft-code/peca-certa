<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

final readonly class RedactToolTrace
{
    private const int MaxBody = 2000;

    /**
     * Redact secrets and truncate tool results for safe persistence on SearchRun.
     */
    public function execute(mixed $result): string
    {
        if (is_string($result)) {
            $text = $result;
        } else {
            try {
                $text = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                $text = '[unserializable tool result]';
            }
        }

        $text = preg_replace(
            '/(access_token|password|pwd|authorization|bearer)\s*[:=]\s*["\']?[^"\'\s,}]+/i',
            '$1=[redacted]',
            $text,
        ) ?? $text;

        $text = preg_replace(
            '/eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/',
            '[redacted-jwt]',
            $text,
        ) ?? $text;

        if (mb_strlen($text) > self::MaxBody) {
            return mb_substr($text, 0, self::MaxBody - 1).'…';
        }

        return $text;
    }
}
