<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\UpdateProjectCosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('costs:update {--months=6 : Calendar months to refresh from project start} {--dry-run : Print summary without writing docs}')]
#[Description('Fetch PL24 / xAI / Cloudflare / Laravel Cloud costs and update docs/costs/')]
final class UpdateCostsCommand extends Command
{
    public function handle(UpdateProjectCosts $updateProjectCosts): int
    {
        $monthsOption = $this->option('months');
        $months = is_numeric($monthsOption) ? (int) $monthsOption : 6;

        if ($months < 1) {
            $this->error('The --months option must be a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $result = $updateProjectCosts->execute($months, $dryRun);

        if ($dryRun) {
            $this->info('Dry run (docs not written).');
        } else {
            $this->info('Updated docs/costs/costs.md and docs/costs/costs.json');
        }

        $this->line('Months: '.implode(', ', $result['months']));

        /** @var array<string, mixed> $info */
        foreach ($result['sources'] as $name => $info) {
            $status = is_string($info['status'] ?? null) ? $info['status'] : 'unknown';
            $reason = is_string($info['reason'] ?? null) ? sprintf(' (%s)', $info['reason']) : '';
            $this->line(sprintf('  %s: %s%s', $name, $status, $reason));
        }

        if ($result['total_eur'] !== null) {
            $this->line(sprintf('Latest month total: %.2f EUR', $result['total_eur']));
        }

        return self::SUCCESS;
    }
}
