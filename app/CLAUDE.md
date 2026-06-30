# app/ Directory Conventions

## Conventions

- `declare(strict_types=1)` at the top of every PHP file.
- All classes use `final` (or `final readonly` for stateless classes such as Actions).
- Constructor property promotion for all injected dependencies.
- Explicit return types on all methods and functions.
- Casts defined via `casts()` method, not the `$casts` property.
- Route key binding: models with slugs override `getRouteKeyName()` returning `'slug'`.
- Auto-slug generation in `booted()` with collision handling via incrementing suffix.
- Use `list<Type>` for sequential arrays, `array<Key, Value>` for associative arrays.
- `env()` is only allowed in `config/` files; everywhere else use `config()`.
- PHP 8.5 features: `array_first()` / `array_last()` for plain arrays (NOT collections), pipe operator `|>` for 3+ sequential transformations.
- PHPStan level max; zero errors required.
- Code quality pipeline: `vendor/bin/rector --no-diffs` → `vendor/bin/pint --dirty --format agent` → `vendor/bin/phpstan analyse --memory-limit=2G`.

## PHPDoc Rules

- Relationship generics: `@return BelongsToMany<User, $this>`, `@phpstan-return BelongsTo<Post, $this>`.
- Attribute accessors: `@return Attribute<string|null, never>`.
- Array shapes: `@return array{key: Type}`, `@var array<string, string>`.
- Collection generics: `Collection<int, Model>` — never bare `Collection`.
- Model class-level: `@property`, `@property-read`, `@property-read Pivot`, `/** @use HasFactory<ModelFactory> */`.
- PHPDoc blocks over inline comments. No inline comments in method bodies unless exceptionally complex.
- PHPDoc NOT required for: fully descriptive native return types, simple boolean checkers, override methods.

## Patterns

Model with slug binding and casts:
```php
/** @property Carbon|null $published_at */
final class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'status' => PostStatus::class];
    }
}
```

Action class with `final readonly` and `execute()`:
```php
final readonly class PublishPost
{
    public function __construct(
        private NotifySubscribersAction $notify,
    ) {}

    public function execute(User $author, Post $post): Post
    {
        return DB::transaction(function () use ($author, $post): Post {
            $post->update(['published_at' => now()]);
            $this->notify->execute($post);
            return $post;
        });
    }
}
```

## Anti-Patterns

- Do not use `$casts` property; use `casts()` method.
- Do not use bare `Collection` in PHPDoc; always specify generics.
- Do not use `DB::` facade for queries; prefer `Model::query()`.
- Do not use `env()` outside config files; use `config()`.
- Do not write inline comments inside method bodies unless logic is exceptionally complex.
- Do not name the primary method `handle()` in Actions; use `execute()`.
