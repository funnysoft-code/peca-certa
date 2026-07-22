<?php

declare(strict_types=1);

use App\Rules\KeysIn;

it('fails when the value is not an array', function (): void {
    $rule = new KeysIn(['search', 'in_stock']);
    $failed = false;

    $rule->validate('filter', 'nope', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

it('fails when an unknown key is present', function (): void {
    $rule = new KeysIn(['search']);
    $message = null;

    $rule->validate('filter', ['foo' => 'bar'], function (string $msg) use (&$message): void {
        $message = $msg;
    });

    expect($message)->toContain('Valid keys are: search');
});

it('passes when all keys are allowed', function (): void {
    $rule = new KeysIn(['search', 'in_stock']);
    $failed = false;

    $rule->validate('filter', ['search' => 'x'], function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});
