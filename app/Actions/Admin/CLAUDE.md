# app/Actions/Admin

Admin domain Actions (invite, role assign, permission matrix sync). Inherits `app/Actions/CLAUDE.md`.

## Conventions
- `final readonly` with single `execute()` (or companion methods on the same Action when tightly coupled, e.g. `InviteUser::resend()`).
- Always clear Spatie permission cache after role/permission mutations via `PermissionRegistrar::forgetCachedPermissions()`.
- Protect last-admin demotion with validation errors and rely on `user:promote` CLI for recovery.
