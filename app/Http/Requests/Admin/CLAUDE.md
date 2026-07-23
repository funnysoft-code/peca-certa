# app/Http/Requests/Admin

Admin-area FormRequests. Inherits `app/Http/Requests/CLAUDE.md`.

## Conventions
- `final` FormRequest, `declare(strict_types=1)`, typed `rules(): array`.
- Authorization lives in policies / controller authorize calls; `authorize()` may return true when middleware + policy already gate the route.
- Validation only — no persistence.
