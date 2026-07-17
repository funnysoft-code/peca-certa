<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Token;
use Illuminate\Support\Facades\Date;

it('is valid while unexpired and invalid once past expiry', function (): void {
    $valid = new PartsLink24Token('jwt', Date::now()->addMinutes(5));
    $expired = new PartsLink24Token('jwt', Date::now()->subSecond());

    expect($valid->isValid())->toBeTrue()
        ->and($expired->isValid())->toBeFalse();
});
