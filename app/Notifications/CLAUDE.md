# app/Notifications

Application notifications (mail, etc.).

## Conventions
- `final` notification classes; implement `ShouldQueue` for outbound mail.
- Branded HTML views under `resources/views/mail/` for product emails (dark R2CZ + teal).
- Pass tokens in the constructor; never log secrets.
