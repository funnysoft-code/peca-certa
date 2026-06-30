# App/Actions guidelines

- This application uses the Action pattern and prefers much logic to live in reusable, composable Action classes.
- Actions live in `app/Actions`, named based on what they do, with **no class-name suffix** (e.g., `CreateUser` not `CreateUserAction`).
- Actions are called from many different places: Jobs, commands, HTTP controllers, API requests, MCP requests, and more.
- Every Action is a `final readonly class` with a single public method: **`execute()`** (never `handle()` — that is reserved for Jobs).
- Inject dependencies via constructor using constructor property promotion.
- Create new actions with `php artisan make:action "{Name}" --no-interaction`. The stub emits `execute()`; if `handle()` appears, rename it.
- Wrap complex, multi-step mutations in `DB::transaction()`.
- Mark secret arguments (passwords, tokens) with `#[\SensitiveParameter]`.
- Some actions require no constructor dependencies — they may use just `execute()` with no constructor.

@boostsnippet('Example action class', 'php')
<?php

declare(strict_types=1);

namespace App\Actions;

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
@endboostsnippet

@boostsnippet('Action with #[SensitiveParameter]', 'php')
<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class RotateApiKey
{
    public function execute(
        User $user,
        #[\SensitiveParameter] string $currentToken,
    ): string {
        // verify then rotate
    }
}
@endboostsnippet
