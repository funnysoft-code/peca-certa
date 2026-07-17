# Event Classes

## Conventions

- `final class`, past-tense noun name: `SearchRunAdvanced`, `SupplierResultReady`.
- Implements `ShouldBroadcast`; uses `Dispatchable`, `InteractsWithSockets`, `SerializesModels`.
- Constructor property promotion for the payload model (public, readonly not required since `SerializesModels` needs to rehydrate it).
- Broadcasts on the private per-run channel `search-run.{id}` via `PrivateChannel`, never a public channel. `broadcastOn()` returns a `list<Channel>`.
- `broadcastAs()` returns a short dot-notation event name (`run.advanced`, `lookup.ready`), matched by the frontend Echo listener.
- `broadcastWith()` never serializes the raw model. Always go through the matching `App\Data\*Data::fromModel()->jsonSerialize()` DTO so the frontend gets a stable, versioned payload shape.
- Preload any relations the DTO needs (e.g. `$run->load('lookups')`) before calling `fromModel()`, inside `broadcastWith()`.
- Authorize the corresponding `search-run.{id}` channel in `routes/channels.php` against the owning user before adding a new event on it.

## Anti-Patterns

- Do not broadcast on a public `Channel` for anything tied to a user's search run.
- Do not put the raw Eloquent model in `broadcastWith()`. Use the Data DTO.
- Do not use em dashes in this file or in event class docblocks.
