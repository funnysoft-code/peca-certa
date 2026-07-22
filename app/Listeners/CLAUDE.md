# Listeners

## Conventions

- `final readonly class` when the listener only holds injected actions/services.
- Prefer named handler methods when one class listens to multiple events
  (`handleInvoking`, `handleInvoked`). Register each pair explicitly in
  `AppServiceProvider` with `Event::listen(Event::class, [Listener::class, 'method'])`.
- Keep side effects thin: call Actions via `execute()`, do not put business rules here.
- Octane-safe: no request-scoped mutable statics or singleton-cached Request/User.

## Anti-Patterns

- Do not invent a no-op `handle()` solely to satisfy arch presets when multi-method
  handlers are already registered (ignore that class in `ArchTest` instead).
- Do not query Eloquent for heavy work; delegate to Actions/Queries.
