<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * xAI text model ids used with Laravel AI's #[Model] attribute.
 *
 * Usage: #[Model(XaiModel::Grok43->value)]
 */
enum XaiModel: string
{
    case Grok43 = 'grok-4.3';
    case Grok45 = 'grok-4.5';
}
