# Database Migrations

## Conventions

- Anonymous class syntax: `return new class extends Migration`.
- Always include `declare(strict_types=1)` at the top.
- Always implement both `up()` and `down()` — even if `down()` is a comment-only no-op.
- PHPDoc blocks on both: `/** Run the migrations. */` / `/** Reverse the migrations. */`
- Blueprint closures use void return type: `function (Blueprint $table): void`.
- PostgreSQL is the primary database — use PostgreSQL-compatible types.
- **Column-modify gotcha (Laravel 13):** when modifying a column, the migration MUST re-declare ALL attributes previously defined on the column; Laravel drops missing attributes silently.
- User table uses `$table->uuid('id')->primary()` (not `$table->id()`).
- Use `$table->foreignUuid('user_id')->constrained()->cascadeOnDelete()` for UUID foreign keys.

## Patterns

Creating a table with UUID foreign key:
```php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

Adding columns with rollback:
```php
return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('display_name', 100)->nullable()->after('name');
            $table->string('bio', 500)->nullable()->after('display_name');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'bio']);
        });
    }
};
```

## Anti-Patterns

- Missing `down()` method entirely — always include it, even with a comment explaining why rollback is impossible.
- Using MySQL-specific syntax (`UNSIGNED`, `TINYINT`) — use Laravel schema builder methods.
- Modifying a column without re-declaring all its existing attributes — they get silently dropped.
- Using `$table->id()` for the User model — use `$table->uuid('id')->primary()`.
- Omitting `declare(strict_types=1)`.
- Using raw SQL when the schema builder supports the operation.
