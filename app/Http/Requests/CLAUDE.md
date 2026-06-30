# Form Request Conventions

## Conventions

- All requests are `final class` extending `FormRequest`.
- `declare(strict_types=1)` at the top of every file.
- Validation rules use array syntax — never pipe-delimited strings: `['required', 'string', 'max:255']`.
- `authorize()` method returns `bool` — `true` for public endpoints, policy/gate check for protected ones.
- `messages()` method for custom, user-friendly error text.
- `toDto()` bridge method converts validated data to a Spatie Data DTO for the Action layer.
- Use `Rule::enum(EnumClass::class)` for enum validation.
- Conditional rules: use `$this->filled('field')` to add rules dynamically.
- Use `prepareForValidation()` to normalize input (e.g., convert empty strings to null).
- PHPDoc return type: `@return array<string, ValidationRule|array<mixed>|string>` for `rules()`.

## Patterns

Request with `toDto()` bridge and enum validation:
```php
declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\CreatePostData;
use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title'  => ['required', 'string', 'min:3', 'max:200'],
            'body'   => ['required', 'string', 'min:10'],
            'status' => ['required', Rule::enum(PostStatus::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'A post title is required.',
            'title.min'      => 'The title must be at least 3 characters.',
        ];
    }

    public function toDto(): CreatePostData
    {
        $validated = $this->validated();

        return new CreatePostData(
            title: $validated['title'],
            body: $validated['body'],
            status: PostStatus::from($validated['status']),
        );
    }
}
```

Conditional rules with `$this->filled()`:
```php
public function rules(): array
{
    $rules = [
        'price_min' => ['nullable', 'numeric', 'min:0'],
        'price_max' => ['nullable', 'numeric', 'min:0'],
    ];

    if ($this->filled('price_min')) {
        $rules['price_max'][] = 'gte:price_min';
    }

    return $rules;
}
```

## Anti-Patterns

- Do not use pipe-delimited rules (`'required|string|max:255'`) — use array syntax.
- Do not pass raw `$request->validated()` arrays to Actions — use `toDto()` to bridge to a DTO.
- Do not skip `messages()` for user-facing forms — always provide human-readable error text.
- Do not hardcode enum values in `in:` rules — use `Rule::enum()` or filtered enum cases.
