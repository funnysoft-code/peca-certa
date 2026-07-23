<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SearchRunKind;
use App\Models\SearchRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a persisted PL24 BOM diagram for an identify run.
 *
 * Files live on the private `pl24_diagrams` disk (not public/storage). Access is
 * gated by SearchRunPolicy::view so only the owner (or manage) can fetch bytes.
 */
final class ShowOePartDiagramController extends Controller
{
    public function __invoke(Request $request, SearchRun $run, string $filename): StreamedResponse
    {
        $this->authorize('view', $run);
        abort_unless($run->kind === SearchRunKind::Identify, 404);

        $filename = basename($filename);
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $filename) === 1, 404);

        $path = 'diagrams/'.$filename;
        abort_unless($this->runReferencesDiagram($run, $path), 404);

        $diskName = config()->string('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        $mime = match (true) {
            str_ends_with(Str::lower($filename), '.png') => 'image/png',
            str_ends_with(Str::lower($filename), '.jpg'), str_ends_with(Str::lower($filename), '.jpeg') => 'image/jpeg',
            str_ends_with(Str::lower($filename), '.gif') => 'image/gif',
            str_ends_with(Str::lower($filename), '.svg') => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return $disk->response($path, $filename, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function runReferencesDiagram(SearchRun $run, string $path): bool
    {
        /** @var list<array<string, mixed>> $parts */
        $parts = $run->oe_parts ?? [];

        foreach ($parts as $part) {
            $diagramPath = $part['diagramPath'] ?? null;

            if (is_string($diagramPath) && $diagramPath === $path) {
                return true;
            }
        }

        return false;
    }
}
