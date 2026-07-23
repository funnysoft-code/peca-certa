<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ResumeIdentifyRun;
use App\Http\Requests\ResumeIdentifyRequest;
use App\Models\SearchRun;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class ResumeIdentifyController extends Controller
{
    public function __invoke(
        ResumeIdentifyRequest $request,
        SearchRun $run,
        ResumeIdentifyRun $resume,
    ): RedirectResponse {
        abort_unless($run->user_id === $this->user($request)->id, 403);

        if ($request->answer() === '' && $request->option() === null) {
            return back()->withErrors([
                'answer' => 'Indique uma opção ou texto livre.',
            ]);
        }

        try {
            $resume->execute($run, $request->answer(), $request->option());
        } catch (InvalidArgumentException $invalidArgumentException) {
            return back()->withErrors([
                'option' => $invalidArgumentException->getMessage(),
            ]);
        }

        return to_route('identify.show', $run);
    }
}
