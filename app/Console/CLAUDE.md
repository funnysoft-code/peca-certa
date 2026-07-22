# Console

Artisan commands live under `app/Console/Commands`.

## Conventions

- `final class` extending `Command`
- Prefer PHP attributes `#[Signature]` / `#[Description]`
- Inject Actions via `handle()` type-hints; keep commands thin

