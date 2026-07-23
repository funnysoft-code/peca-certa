# Ai/Attributes Directory

PHP attributes applied to `App\Ai\Agents` for xAI provider configuration.

## Conventions

- `final readonly class` with `#[Attribute(Attribute::TARGET_CLASS)]`.
- `declare(strict_types=1)` at the top of every file.
- Keep attributes thin: constructor-promoted public props only, no business logic.
- Read attributes via `ReflectionClass` from concerns (e.g. `UsesXaiProviderOptions`), not from controllers or actions.

## Current attributes

- `#[Reasoning(ReasoningEffort::…)]` — sets Responses API `reasoning.effort`. Enum: `App\Enums\ReasoningEffort`.
