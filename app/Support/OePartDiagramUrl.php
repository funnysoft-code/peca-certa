<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SearchRun;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Browser-reachable URL for a persisted OE diagram path.
 *
 * - S3 / Cloud object storage: temporary signed URL when available.
 * - Local / private disks: auth-gated app route (ShowOePartDiagramController).
 */
final class OePartDiagramUrl
{
    public static function for(SearchRun $run, string $path): string
    {
        $path = mb_ltrim(str_replace('\\', '/', $path), '/');
        $diskName = config()->string('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
        $driver = config('filesystems.disks.'.$diskName.'.driver');
        $driver = is_string($driver) ? $driver : 'local';

        if ($driver === 's3') {
            try {
                return Storage::disk($diskName)->temporaryUrl($path, now()->addDay());
            } catch (Throwable) {
                // Fall through to the auth-gated app route.
            }
        }

        $filename = basename($path);

        return route('identify.diagram', [
            'run' => $run,
            'filename' => $filename,
        ]);
    }
}
