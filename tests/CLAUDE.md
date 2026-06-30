# Test Suite

## Conventions

- Pest 5 function-based tests: `test('description', function (): void { })` or `it('description', function (): void { })`.
- Always include `declare(strict_types=1)` at the top of test files.
- `tests/Pest.php` auto-applies `RefreshDatabase` to all Feature tests — do not re-add it manually.
- Test database: SQLite `:memory:` (configured via `phpunit.xml` `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`).
- Test organization mirrors app structure: `tests/Feature/Auth/`, `tests/Feature/Posts/`, etc.
- Create tests with `php artisan make:test --pest {name}` (feature) or `--pest --unit` (unit).
- **Type coverage gate:** `vendor/bin/pest --type-coverage --min=100`. Type coverage is PHP-only — there is no `typeCoverage()` Pest helper; exclude files via `phpunit.xml` test-suite exclusions or `@pest-type-coverage-ignore` annotations where supported.
- **Coverage gate:** `XDEBUG_MODE=coverage vendor/bin/pest --parallel --coverage --exactly=100.0 --exclude-testsuite=Browser`.
- **Never delete a test without explicit approval.**

## Running Tests

- All tests (parallel): `php artisan test --parallel --compact`
- Single file: `php artisan test --compact tests/Feature/Posts/CreatePostTest.php`
- Filter by name: `php artisan test --compact --filter=testName`
- Browser tests only: `vendor/bin/pest --testsuite=Browser` (serial in CI)
- Quality gate: `bin/quality-gate.sh` (or `/qg`)

## phpunit.xml Test Overrides

- `APP_ENV=testing`
- `APP_MAINTENANCE_DRIVER=file`
- `BCRYPT_ROUNDS=4`
- `BROADCAST_CONNECTION=null`
- `CACHE_STORE=array`
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:`
- `INERTIA_SSR_ENABLED=false`
- `MAIL_MAILER=array`
- `QUEUE_CONNECTION=sync`
- `SESSION_DRIVER=array`
- `TELESCOPE_ENABLED=false`
- `NIGHTWATCH_ENABLED=false` (when Nightwatch is installed)
- `PULSE_ENABLED=false` (when Pulse is installed)

## Patterns

Authenticated user test:
```php
declare(strict_types=1);

test('authenticated users can create a post', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('posts.store'), [
        'title' => 'Hello World',
        'body'  => 'This is the post body.',
    ]);

    $response->assertRedirect(route('posts.index'));
    $this->assertDatabaseHas('posts', ['title' => 'Hello World', 'user_id' => $user->id]);
});
```

Event faking:
```php
test('publishing a post fires the PostPublished event', function (): void {
    Event::fake();
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();

    $this->actingAs($user)->post(route('posts.publish', $post));

    Event::assertDispatched(PostPublished::class);
});
```

Arch test (enforce Action conventions):
```php
arch('Actions are final readonly and have execute()')
    ->expect('App\Actions')
    ->toBeReadonly()
    ->toBeFinal()
    ->toHaveMethod('execute');
```

## Browser Tests (`tests/Browser/`)

Pest 5 browser tests via `pestphp/pest-plugin-browser` + Playwright. Run serially (`--testsuite=Browser`). Failed screenshots land in `tests/Browser/Screenshots/` (gitignored).

### CRITICAL: Hydration waits

**Always call `$page->waitForEvent('networkidle')` after `visit()` (and after any in-page navigation that re-mounts a form) BEFORE filling or pressing anything.**

Reason: Inertia + React hydrates asynchronously. `fill('#input')` sets the native value but the React `onChange` handler is not attached yet, so React state never sees the typed value — the server gets an empty field. Parallel-worker contention makes this race worse.

```php
// Bad — flaky
$page = visit('/login');
$page->fill('#email', 'user@example.com');

// Good — deterministic
$page = visit('/login');
$page->waitForEvent('networkidle');
$page->fill('#email', 'user@example.com');
```

Insert another `waitForEvent('networkidle')` after any click that triggers a route change, before the next fill or assertion.

### Gotchas

- `assertSee` does NOT auto-wait — it throws immediately if the text is missing.
- `waitForText` behaves identically to `assertSee` (same instant-fail behavior).
- Browser CI job is serial; if a test fails only in CI, retry the file in isolation first.

## Anti-Patterns

- Filling browser inputs without first calling `waitForEvent('networkidle')` after `visit()`.
- Using `sleep()` / `wait(seconds)` in browser tests as a flake band-aid — fix the missing wait condition.
- Manually building models without factories: `new User(['name' => ...])`.
- Not checking factory custom states before manually setting up attributes.
- Using `env()` in tests — rely on `phpunit.xml` overrides.
- Running the full suite when only a few tests are relevant — use `--filter` or specific file paths.
- Forgetting that `RefreshDatabase` is auto-applied to Feature tests.
