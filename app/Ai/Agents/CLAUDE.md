# Ai/Agents Directory

## Conventions

- `final class` implementing `Laravel\Ai\Contracts\Agent`, using the `Promptable` trait.
- `declare(strict_types=1)` at the top of every file.
- Structured-output agents implement `HasStructuredOutput` and define `schema(JsonSchema $schema): array` using the `Illuminate\JsonSchema` builder (`string()`, `array()->items()`, `number()->min()->max()`, `nullable()`, `required()`).
- Provider/model come from `config/ai.php` (`AI_DEFAULT_PROVIDER`) unless an agent needs to pin a specific provider via the `#[Provider]` / `#[Model]` attributes.
- Instructions and any user-facing clarifying text are written in European Portuguese for this app's domain (parts identification for a Portuguese workshop).
- Agents are invoked from Actions (`app/Actions/`), never directly from controllers.
- Test with `Agent::fake([...])`. Each faked response is either a plain string (text agents) or an assoc array matching the schema (structured agents), consumed in call order.
