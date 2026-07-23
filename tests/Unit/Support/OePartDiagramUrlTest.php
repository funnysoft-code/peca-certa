<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Models\SearchRun;
use App\Support\OePartDiagramUrl;
use Illuminate\Support\Facades\Storage;

it('uses the auth-gated identify route for local private disks', function (): void {
    config()->set([
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
        'filesystems.disks.pl24_diagrams.driver' => 'local',
    ]);

    $run = SearchRun::factory()->create(['kind' => SearchRunKind::Identify]);

    $url = OePartDiagramUrl::for($run, 'diagrams/hash.png');

    expect($url)->toBe(route('identify.diagram', [
        'run' => $run,
        'filename' => 'hash.png',
    ]));
});

it('prefers temporaryUrl when the diagrams disk driver is s3', function (): void {
    config()->set([
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
        'filesystems.disks.pl24_diagrams' => [
            'driver' => 's3',
            'key' => 'testing',
            'secret' => 'testing',
            'region' => 'us-east-1',
            'bucket' => 'testing',
            'throw' => true,
        ],
    ]);

    Storage::fake('pl24_diagrams');
    Storage::disk('pl24_diagrams')->put('diagrams/hash.png', 'png');

    $run = SearchRun::factory()->create(['kind' => SearchRunKind::Identify]);
    $url = OePartDiagramUrl::for($run, 'diagrams/hash.png');

    // Laravel's fake S3 adapter still mints a temporary-style URL; must not fall back
    // to the app auth route for successful temporaryUrl calls.
    expect($url)->not->toContain('/identify/'.$run->id.'/diagrams/');
});

it('falls back to the auth route when s3 temporaryUrl throws', function (): void {
    config()->set([
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
        'filesystems.disks.pl24_diagrams' => [
            'driver' => 's3',
            'key' => 'testing',
            'secret' => 'testing',
            'region' => 'us-east-1',
            'bucket' => 'testing',
            'throw' => true,
        ],
    ]);

    // No Storage::fake — real S3 adapter without credentials throws on temporaryUrl.
    $run = SearchRun::factory()->create(['kind' => SearchRunKind::Identify]);
    $url = OePartDiagramUrl::for($run, 'diagrams/hash.png');

    expect($url)->toBe(route('identify.diagram', [
        'run' => $run,
        'filename' => 'hash.png',
    ]));
});
