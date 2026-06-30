# app/Http/Requests/Settings

Settings-area FormRequests. Inherits the conventions in `app/Http/Requests/CLAUDE.md`.

## Conventions
- `final` FormRequest, `declare(strict_types=1)`, typed `rules(): array`.
- Authorization in `authorize()`; validation only — no persistence.

## Patterns
- Reuse shared rule sets from `app/Concerns` (e.g. `PasswordValidationRules`, `ProfileValidationRules`).

## Anti-Patterns
- Performing the update inside the request — delegate to a controller→Action.
