# Findings

Pure mapping helpers for denormalized finding rows.

## Conventions

- Stateless `final class` with static pure methods (no I/O).
- Price freeze rules live here: Auto Delta = purchase, Auto Zitânia = retail.
- Persistence (insert/delete) belongs in Actions (`PersistLookupFindings`), not here.
