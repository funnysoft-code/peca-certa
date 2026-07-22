<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;

it('exposes the search run and lookup enum values', function (): void {
    expect(SearchRunKind::Identify->value)->toBe('identify')
        ->and(SearchRunKind::Parts->value)->toBe('parts')
        ->and(SearchRunStatus::cases())->toHaveCount(6)
        ->and(SearchRunStatus::Pending->value)->toBe('pending')
        ->and(SearchRunStatus::NeedsInput->value)->toBe('needs_input')
        ->and(SearchRunStatus::Cancelled->value)->toBe('cancelled')
        ->and(SupplierLookupStatus::Empty->value)->toBe('empty')
        ->and(SupplierLookupStatus::cases())->toHaveCount(5);
});
