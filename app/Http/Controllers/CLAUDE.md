# HTTP Controllers Conventions

## Conventions

- All controllers are `final readonly class` extending `Controller`.
- After `make:controller`, add your Action as a typed method parameter alongside `#[CurrentUser] User $user` and call `$action->execute(...)` — the stub leaves a comment showing the injection point.
- The Action class is injected as a **method parameter**, not in the constructor.
- Current authenticated user is resolved via the **`#[CurrentUser]`** attribute (not `auth()->user()` inline).
- Validation lives exclusively in FormRequest classes — never inline in controllers.
- Return types: `Response` (Inertia pages), `RedirectResponse` (mutations), `JsonResponse` (AJAX endpoints).
- Authorization via `Gate::authorize()` or policy checks; never inline boolean checks.
- Flash session messages use `->with('status', 'message text')` (consumed by Sonner toast middleware).
- Named route redirects: `to_route('resource.action', $model)` — never `redirect('/path')`.
- All routes are web routes (no `api.php`). Inertia for pages, JSON only for true AJAX endpoints.
- No business or write logic in controllers — delegate entirely to Actions via `$action->execute()`.

## Patterns

Standard Inertia read + mutation pair:
```php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreatePost;
use App\Attributes\CurrentUser;
use App\Http\Requests\CreatePostRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

final readonly class PostController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('posts/index', [
            'posts' => Post::query()->latest()->paginate(20),
        ]);
    }

    public function store(
        CreatePostRequest $request,
        CreatePost $action,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        $post = $action->execute($user, $request->validated('title'), $request->validated('body'));

        return to_route('posts.show', $post)
            ->with('status', 'Post published successfully.');
    }
}
```

Controller with deferred Inertia props:
```php
public function show(Post $post): Response
{
    return Inertia::render('posts/show', [
        'post'     => $post,
        'comments' => Inertia::defer(fn () => $post->comments()->with('author')->get()),
    ]);
}
```

## Anti-Patterns

- Do not inject Actions in the constructor — inject them as method parameters.
- Do not put business logic in controllers — delegate to Actions via `$action->execute()`.
- Do not validate inline — always use dedicated FormRequest classes.
- Do not use `redirect('/path')` — use `to_route()` or `back()`.
- Do not use `auth()->user()` inline — use `#[CurrentUser]` attribute.
- Do not use `env()` in controllers — use `config()`.
