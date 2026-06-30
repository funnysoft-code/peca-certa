<?php

declare(strict_types=1);

use NunoMaduro\Essentials\Configurables\AggressivePrefetching;
use NunoMaduro\Essentials\Configurables\AutomaticallyEagerLoadRelationships;
use NunoMaduro\Essentials\Configurables\FakeSleep;
use NunoMaduro\Essentials\Configurables\ForceScheme;
use NunoMaduro\Essentials\Configurables\ImmutableDates;
use NunoMaduro\Essentials\Configurables\PreventStrayRequests;
use NunoMaduro\Essentials\Configurables\ProhibitDestructiveCommands;
use NunoMaduro\Essentials\Configurables\SetDefaultPassword;
use NunoMaduro\Essentials\Configurables\ShouldBeStrict;
use NunoMaduro\Essentials\Configurables\Unguard;

return [

    AggressivePrefetching::class => true,

    AutomaticallyEagerLoadRelationships::class => true,

    FakeSleep::class => true,

    ForceScheme::class => true,

    'environments' => [
        ForceScheme::class => ['production'],
    ],

    ImmutableDates::class => true,

    PreventStrayRequests::class => true,

    ProhibitDestructiveCommands::class => true,

    // Password policy is owned by AppServiceProvider::boot() (prod: min 12 + uncompromised;
    // dev/testing: min 8), so the essentials default-password configurable is disabled to
    // avoid two competing Password::defaults() registrations.
    SetDefaultPassword::class => false,

    ShouldBeStrict::class => true,

    // Models are unguarded (no $fillable/$guarded); input is gated by FormRequests.
    Unguard::class => true,

];
