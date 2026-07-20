<?php

declare(strict_types=1);

it('defines serialized supervisors for the single-session suppliers', function (): void {
    $defaults = config('horizon.defaults');

    expect($defaults)->toHaveKeys(['supervisor-autodelta', 'supervisor-zitania', 'supervisor-partslink24'])
        ->and($defaults['supervisor-zitania']['queue'])->toBe(['zitania'])
        ->and($defaults['supervisor-zitania']['maxProcesses'])->toBe(1)
        ->and($defaults['supervisor-partslink24']['maxProcesses'])->toBe(1)
        ->and($defaults['supervisor-autodelta']['queue'])->toBe(['autodelta']);
});
