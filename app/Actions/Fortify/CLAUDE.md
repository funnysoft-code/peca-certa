# app/Actions/Fortify

Fortify-contract actions (registration, password reset, etc.).

## Conventions
- These implement Laravel Fortify contracts (`CreatesNewUsers`, `ResetsUserPasswords`, …) and therefore use the contract's method name (`create()`, `reset()`) — **the documented exception** to the project-wide `execute()` Action convention.
- `final` classes, `declare(strict_types=1)`, `#[\SensitiveParameter]` on password args.

## Patterns
- Compose shared validation from `app/Concerns` traits; persist via `User::query()->create(...)`.

## Anti-Patterns
- Renaming the contract methods to `execute()` (breaks Fortify wiring).
- Putting non-Fortify business logic here — that belongs in a normal `app/Actions` `execute()` Action.
