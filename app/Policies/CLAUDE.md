# Policies

## Conventions

- Every policy is `final class`.
- `declare(strict_types=1)` at the top of every file.
- First parameter is always `User $user`; second parameter is the model being authorized.
- Standard verb-based method names: `view`, `create`, `update`, `delete`, `restore`, `forceDelete`.
- Custom actions use descriptive verbs: `publish`, `archive`, `assign`, `transferOwnership`.
- Delegate authorization checks to model methods: `isOwner()`, `hasMember()`, `isAdmin()`.
- `create()` takes only `User` (no model) when checking creation eligibility.
- All methods return `bool`.
- Add PHPDoc blocks for non-obvious authorization logic (e.g., visibility rules for private resources).

## Patterns

Policy with ownership and role-based checks:
```php
declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

final class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        if ($post->isPublished()) {
            return true;
        }

        return $post->user_id === $user->id;
    }

    public function update(User $user, Post $post): bool
    {
        return $post->user_id === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        return $post->user_id === $user->id || $user->isAdmin();
    }
}
```

Policy with resource-limit check on `create()`:
```php
final class PostPolicy
{
    public function create(User $user): bool
    {
        return $user->posts()->count() < config('limits.max_posts_per_user');
    }
}
```

## Anti-Patterns

- Do not inline authorization logic in controllers — always use policies or gates.
- Do not query the database directly in policies — delegate to model methods.
- Do not forget admin overrides where applicable (`$user->isAdmin()`).
- Do not return `Response` objects from policy methods — return `bool` consistently.
