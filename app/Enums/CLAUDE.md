# app/Enums/ Directory Conventions

## Conventions

- `declare(strict_types=1)` at the top of every file.
- All enums are backed string enums: `enum Name: string`.
- Case naming: TitleCase cases with snake_case backing values — `case PostCreate = 'post_create'`.
- Common methods: `label()` returning human-readable text, `options()` returning `array<string, string>` for select fields.
- Use `match ($this)` expressions exhaustively — cover all cases explicitly.
- Mark legacy cases with `/** @deprecated Kept for backwards compatibility with existing DB rows */` — never delete.
- Large enums group related cases with inline comments.
- Use `HasValues` trait (from `App\Enums\Traits\HasValues`) for the `values()` static method if needed.

## Patterns

Simple enum with label and options:
```php
declare(strict_types=1);

namespace App\Enums;

enum PostStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Published => 'Published',
            self::Archived  => 'Archived',
        };
    }
}
```

Enum with a deprecated case for DB compatibility:
```php
enum UserRole: string
{
    case Admin     = 'admin';
    case Editor    = 'editor';
    /** @deprecated Kept for backwards compatibility with existing DB rows */
    case SuperUser = 'super_user';
}
```

## Anti-Patterns

- Do not use integer-backed enums — all enums are string-backed.
- Do not use SCREAMING_CASE for case names — use TitleCase (`PostCreate` not `POST_CREATE`).
- Do not delete deprecated cases — mark them `@deprecated` to preserve DB compatibility.
- Do not use non-exhaustive `match` expressions — cover every case explicitly.
