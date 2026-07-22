# Queries

List query surfaces for QueryBuilder-backed endpoints.

## Conventions

- `final class` with a single public `paginate(...)` (or `list`) method.
- Depend on `Illuminate\Http\Request` + primitives (`int $perPage`), never FormRequests (Laravel arch preset).
- Scope by domain (e.g. `search_run_id`) before applying `QueryBuilder::for`.
- Explicit `allowedFilters` / `allowedSorts` (variadic in spatie/laravel-query-builder v7).
- Return `LengthAwarePaginator` with `appends($request->query())` so links keep the filter string.
