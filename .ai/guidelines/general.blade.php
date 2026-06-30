# General Guidelines

- Don't include superfluous PHP annotations; only use `@` blocks for typing variables, generics, and PHPStan hints.
- `declare(strict_types=1)` is required at the top of every PHP file — enforced by Pint.
- All classes are `final` (or `final readonly` for stateless classes). Never omit `final` without explicit reason.
- Use PHP 8 constructor property promotion for all injected dependencies.
- Explicit return types on all methods and functions — no implicit returns.
- `casts()` method, not the `$casts` property.
- `env()` is ONLY allowed inside `config/` files; everywhere else use `config('key')`.
- Array types: use `list<Type>` for sequential arrays and `array<Key, Value>` for associative arrays.
- Never call `DB::` facade for queries — prefer `Model::query()`.
- PHPDoc blocks take priority over inline comments. Inline comments are reserved for exceptionally complex logic only.
- Code quality pipeline order (must all pass): `rector --no-diffs` → `pint --dirty --format agent` → `phpstan analyse --memory-limit=2G`.
- PHPStan level max; no baseline; zero errors required.

## Naming

- Actions: descriptive verb phrases, no suffix — `CreatePost`, `SendWelcomeEmail`.
- Controllers: `final readonly class`, suffixed `Controller` — `PostController`.
- Jobs: suffixed `Job` — `ProcessImageJob`.
- Policies: suffixed `Policy` — `PostPolicy`.
- Events: past-tense noun — `PostCreated`.
- Listeners: agent-noun phrase — `SendPostNotification`.
- Requests: `CreatePostRequest`, `UpdatePostRequest`.

## PHPDoc conventions

- Relationship generics: `@return HasMany<Post, $this>`, `@phpstan-return BelongsTo<User, $this>`.
- Attribute accessors: `@return Attribute<string|null, never>`.
- Array shapes: `@return array{key: Type}`.
- Collection generics: `Collection<int, Model>` — never bare `Collection`.
- Model class-level: `@property`, `@property-read`, `/** @@use HasFactory<ModelFactory> */`.
- PHPDoc NOT required for: fully descriptive native return types, simple boolean checkers, override methods.
