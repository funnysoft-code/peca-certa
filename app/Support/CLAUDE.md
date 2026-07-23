# Support utilities

- Stateless helpers shared across layers (jobs, actions, services). Prefer Actions for multi-step business mutations.
- `SupplierSessionLock` is the **only** place for supplier portal `WithoutOverlapping` keys. Do not invent ad-hoc keys (`zitania`, `partslink24`, etc.) in jobs.
- Zitânia: one global session for pricing **and** any future identify/plate work — same key always.
- PartsLink24: shared session mutex across `IdentifyAgentJob` + `IdentifyOePartsJob` (and any future PL24 jobs).
- Locks use `->shared()` so different job classes serialize on the same key.
