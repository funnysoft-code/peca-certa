<?php

declare(strict_types=1);

use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id): bool => $user->id === $id);

Broadcast::channel('search-run.{id}', fn (User $user, string $id): bool => SearchRun::query()->whereKey($id)->value('user_id') === $user->id);
