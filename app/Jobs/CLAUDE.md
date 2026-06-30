# Queued Jobs Conventions

## Conventions

- All jobs are `final class` implementing `ShouldQueue`.
- Use the `Queueable` trait only (not separate `Dispatchable`, `InteractsWithQueue`, `SerializesModels`).
- Add `Batchable` trait when the job participates in a `Bus::batch()`.
- Primary method is **`handle()`** — never `execute()` (that convention is for Action classes).
- Configure retry behavior with PHP attributes: `#[Timeout]`, `#[Tries]`, `#[Backoff]` — not public properties.
- Implement `failed(?Throwable $exception)` for failure handling, cleanup, and status updates.
- Check `$this->batch()?->cancelled()` early in batchable jobs to short-circuit gracefully.
- Constructor uses property promotion for all parameters.
- `declare(strict_types=1)` at the top of every file.

## Patterns

Job with retry attributes, batch support, and failure handling:
```php
declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Timeout(180)]
#[Tries(3)]
#[Backoff(30)]
final class ProcessImportJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Job logic...
    }

    public function failed(?Throwable $exception): void
    {
        Import::query()->find($this->importId)?->update([
            'status' => ImportStatus::Failed,
            'error'  => $exception?->getMessage(),
        ]);
    }
}
```

Long-running job (e.g., AI / media processing):
```php
#[Timeout(1800)]
#[Tries(1)]
final class GenerateImageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $jobId,
    ) {}

    public function handle(): void { /* ... */ }

    public function failed(?Throwable $exception): void
    {
        GenerationJob::query()
            ->where('id', $this->jobId)
            ->update(['status' => JobStatus::Failed]);
    }
}
```

## Anti-Patterns

- Do not name the primary method `execute()` — jobs use `handle()`.
- Do not omit `failed()` — always handle failures.
- Do not use `$timeout`, `$tries`, `$backoff` properties — use `#[Timeout]`, `#[Tries]`, `#[Backoff]` attributes.
- Do not forget the batch cancellation check in batchable jobs.
- Do not use `Dispatchable` trait separately — `Queueable` covers dispatch.
- Do not use `Model::find()` without `::query()->find()` — prefer the query-builder form.
