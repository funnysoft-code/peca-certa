# AI agent concerns

Shared traits for `App\Ai\Agents` (provider options, cross-cutting agent helpers).

- Keep traits pure and Octane-safe: no mutable static state.
- Provider-specific options stay behind config keys under `config/ai.php` (e.g. `service_tier`, prompt cache keys).
- Reasoning effort comes from `#[Reasoning(ReasoningEffort::…)]` on the agent (defaults to `Low` if omitted).
