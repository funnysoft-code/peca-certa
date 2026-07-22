<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class StartPartsSearch
{
    public function execute(User $user, string $reference): SearchRun
    {
        $run = DB::transaction(function () use ($user, $reference): SearchRun {
            $run = SearchRun::query()->create([
                'user_id' => $user->id,
                'kind' => SearchRunKind::Parts,
                'reference' => $reference,
                'status' => SearchRunStatus::Running,
            ]);

            foreach ([Supplier::AutoDelta, Supplier::AutoZitania] as $supplier) {
                SupplierLookup::query()->create([
                    'search_run_id' => $run->id,
                    'supplier' => $supplier,
                    'query' => $reference,
                    'status' => SupplierLookupStatus::Pending,
                ]);
            }

            return $run;
        });

        $run->load('lookups');

        foreach ($run->lookups as $lookup) {
            dispatch(new PriceSupplierJob($lookup));
        }

        return $run;
    }
}
