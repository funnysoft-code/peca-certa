<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CancelSearchRun;
use App\Models\SearchRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CancelIdentifyController extends Controller
{
    public function __invoke(
        Request $request,
        SearchRun $run,
        CancelSearchRun $cancel,
    ): RedirectResponse {
        $this->authorize('update', $run);

        try {
            $cancel->execute($run);
        } catch (InvalidArgumentException) {
            return back()->withErrors([
                'run' => 'Esta identificação já não pode ser cancelada.',
            ]);
        }

        return to_route('identify.show', $run)
            ->with('status', 'Identificação cancelada.');
    }
}
