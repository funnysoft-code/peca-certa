# Action Classes

## Conventions

- `final readonly class` for all Actions.
- One public method: `execute()` — never `handle()` (that is reserved for Jobs/Listeners).
- No class-name suffix: `CreatePost`, not `CreatePostAction`.
- Constructor injection for all dependencies via constructor property promotion.
- Domain-organized subdirectories when a domain has 2+ Actions: `Actions/Post/`, `Actions/Auth/`, etc.
- Wrap multi-step mutations in `DB::transaction()`.
- Mark secret arguments (passwords, API tokens) with `#[\SensitiveParameter]`.
- Return typed models or values for internal operations; `array{success: bool, message: string}` only for user-facing operations that need both outcomes in-band.
- Custom generator: `php artisan make:action "{Name}" --no-interaction` — stub emits `execute()`.

## Patterns

Simple action with DB transaction:
```php
declare(strict_types=1);

namespace App\Actions;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreatePost
{
    public function __construct(
        private NotifyFollowersAction $notify,
    ) {}

    public function execute(User $author, string $title, string $body): Post
    {
        return DB::transaction(function () use ($author, $title, $body): Post {
            $post = Post::query()->create([
                'user_id' => $author->id,
                'title'   => $title,
                'body'    => $body,
            ]);

            $this->notify->execute($author, $post);

            return $post;
        });
    }
}
```

Action with `#[\SensitiveParameter]`:
```php
final readonly class ResetPassword
{
    public function execute(
        User $user,
        #[\SensitiveParameter] string $newPassword,
    ): void {
        $user->update(['password' => Hash::make($newPassword)]);
    }
}
```

No-dependency action:
```php
final readonly class GenerateSlug
{
    public function execute(string $title): string
    {
        return Str::slug($title);
    }
}
```

## Anti-Patterns

- Do not name the method `handle()` — use `execute()`. Jobs and Listeners use `handle()`.
- Do not put business logic in Controllers — extract to Actions.
- Do not inject `Request` objects — accept typed parameters or Spatie Data DTOs.
- Do not skip `DB::transaction()` when multiple write operations depend on each other.
- Do not use `class` without `final readonly` — Actions are stateless by design.
- Do not add a suffix like `Action` to the class name.
