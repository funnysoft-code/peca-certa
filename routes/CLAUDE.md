# Route Definitions

## Conventions

- All web routes live in `web.php` — there is no `api.php`.
- Scheduling lives in `console.php`.
- Broadcast channel authorization lives in `channels.php`.
- Always include `declare(strict_types=1)` at the top.
- All routes must be named using `->name('resource.action')` convention.
- Run `php artisan wayfinder:generate --with-form` after route changes to regenerate TypeScript route functions.
- Use slug-based model binding where models have slugs: `{post:slug}`.
- `HandlePrecognitiveRequests` is appended to the `web` group in `bootstrap/app.php` — do not re-add per route.

## Middleware Groups (outermost to innermost)

- `guest` — unauthenticated only (login/register)
- `auth` — authenticated (settings, profile)
- `auth, verified` — authenticated + email verified
- `auth, verified` + `EnsureHasCompletedOnboarding` (or equivalent) — full app access

## Patterns

Standard resource with auth sub-group:
```php
declare(strict_types=1);

use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

Route::get('posts', [PostController::class, 'index'])->name('posts.index');
Route::get('posts/{post:slug}', [PostController::class, 'show'])->name('posts.show');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::put('posts/{post:slug}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('posts/{post:slug}', [PostController::class, 'destroy'])->name('posts.destroy');
});
```

Broadcast channel authorization:
```php
Broadcast::channel('user.{id}', fn (User $user, int $id): bool => $user->id === $id);
```

Scheduled commands (console.php):
```php
Schedule::command('app:prune-stale-sessions')
    ->daily()
    ->environments('production');
```

## Anti-Patterns

- Adding routes without names.
- Creating `api.php` routes — all routes go through `web.php`.
- Forgetting `php artisan wayfinder:generate --with-form` after route changes.
- Using closures for route actions in production — use controller classes.
- Nesting middleware groups incorrectly.
