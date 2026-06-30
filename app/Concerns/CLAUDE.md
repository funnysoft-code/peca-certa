# app/Concerns

Reusable traits (validation rule sets, shared behaviors).

## Conventions
- Trait name describes the capability (`PasswordValidationRules`, `ProfileValidationRules`).
- `declare(strict_types=1)`; methods return precisely-typed values (e.g. `list<ValidationRule|string>`).

## Patterns
- Pure, stateless helpers consumed by FormRequests and Fortify actions.

## Anti-Patterns
- Stateful traits or hidden side effects.
- Using a trait where a small injectable service/Action would be clearer.
