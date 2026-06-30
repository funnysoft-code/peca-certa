# Data Directory

## Conventions

- `final readonly class` for all DTO classes.
- `declare(strict_types=1)` at the top of every file.
- Classes annotated with `#[TypeScript]` are auto-transformed to `resources/js/types/generated.d.ts`.
- Use `list<Type>` for sequential arrays, PHPDoc array shapes for structured arrays.
- Static factory methods (e.g. `merge()`) return a new DTO instance.
- No mutable state — all properties are `public readonly`.
