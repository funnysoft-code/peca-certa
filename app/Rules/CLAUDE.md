# Custom Validation Rules

## Conventions

- All rules are `final class` implementing `ValidationRule`.
- `declare(strict_types=1)` at the top of every file.
- `validate(string $attribute, mixed $value, Closure $fail): void` is the sole public method.
- Call `$fail('Human-readable message.')` to report a failure — return immediately after.
- Rules are reusable and must not depend on HTTP context (no `request()` calls inside).
- Named descriptively: `UniqueSlug`, `ValidCronExpression`, `StrongPassword`.
- Use `#[Attribute]` PHP attribute when the rule should also work as a PHP attribute.

## Patterns

Simple rule that wraps a library check:
```php
declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCronExpression implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');
            return;
        }

        if (! \Cron\CronExpression::isValidExpression($value)) {
            $fail('The :attribute is not a valid cron expression.');
        }
    }
}
```

Rule with constructor dependency (e.g., uniqueness with exclusion):
```php
final class UniqueSlug implements ValidationRule
{
    public function __construct(
        private readonly ?int $excludeId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Post::query()
            ->where('slug', $value)
            ->when($this->excludeId, fn ($q) => $q->where('id', '!=', $this->excludeId))
            ->exists();

        if ($exists) {
            $fail('The :attribute is already taken.');
        }
    }
}
```

## Anti-Patterns

- Do not throw exceptions in rules — call `$fail()` and return.
- Do not depend on global state (`auth()`, `request()`) inside a rule — pass context via constructor.
- Do not skip the `final` modifier.
- Do not use the legacy `Rule` contract — implement `ValidationRule`.
