# Filters

Spatie QueryBuilder custom filter classes for list endpoints.

## Conventions

- `final class` implementing `Spatie\QueryBuilder\Filters\Filter`.
- `declare(strict_types=1)`.
- One filter class per multi-column / non-trivial filter (`SearchFindingsFilter`).
- Prefer `AllowedFilter::exact` / `partial` / `callback` for simple cases; custom classes only when the logic is shared or multi-column OR.
