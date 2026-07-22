<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SearchRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case NeedsInput = 'needs_input';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Cancelled => true,
            self::Pending, self::Running, self::NeedsInput => false,
        };
    }

    public function isCancellable(): bool
    {
        return match ($this) {
            self::Pending, self::Running, self::NeedsInput => true,
            self::Done, self::Failed, self::Cancelled => false,
        };
    }
}
