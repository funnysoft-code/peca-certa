# app/Http/Controllers/Admin

Admin-area controllers (`/admin/*`). Inherits `app/Http/Controllers/CLAUDE.md`.

## Conventions
- Gate every action with Spatie permissions (`admin.access`, `users.*`, `roles.*`).
- Prefer standard REST method names on resource controllers; use invokable controllers for non-REST actions (resend invite, role assign).
- Mutations go through Actions under `app/Actions/Admin`.
- Flash success toasts via `Inertia::flash('toast', ...)`.

## Anti-Patterns
- Super-admin `Gate::before` bypass — admin privilege is seeded permissions only.
- Trusting Inertia `auth.can` alone; always authorize server-side.
