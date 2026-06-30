# app/Http/Controllers/Settings

Settings-area controllers. Inherits `app/Http/Controllers/CLAUDE.md`.

## Conventions
- `final` controllers; one responsibility per action method.
- Validate via a FormRequest, delegate mutations to an Action, return an Inertia page or `to_route()` redirect with a flash status.

## Patterns
- `$request->user()` is `?User`; guard or rely on the `auth` middleware + a typed accessor before calling methods on it.

## Anti-Patterns
- Inline business logic or direct multi-step writes — use an Action.
