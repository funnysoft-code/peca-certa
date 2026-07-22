# Ai/Tools Directory

Laravel AI `Tool` implementations used by agents under `app/Ai/Agents`.

- `final class` implementing `Laravel\Ai\Contracts\Tool`.
- `declare(strict_types=1)`.
- Return JSON strings from `handle()` for structured tool results.
- Domain tools live in subfolders (e.g. `PartsLink24/`).
