<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RegisterPartsLink24Catalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('partslink24:register-catalog {key : Brand key (e.g. man)} {service : PL24 service name (e.g. man_parts)} {group : p5 group prefix (e.g. p5man)} {--wmi=* : Optional WMI codes to map to this brand}')]
#[Description('Register a PartsLink24 catalog at runtime (no full config deploy)')]
final class RegisterPartsLink24CatalogCommand extends Command
{
    public function handle(RegisterPartsLink24Catalog $register): int
    {
        try {
            $result = $register->execute(
                (string) $this->argument('key'),
                (string) $this->argument('service'),
                (string) $this->argument('group'),
                array_values(array_filter(
                    array_map(fn (mixed $v): string => is_string($v) ? $v : '', (array) $this->option('wmi')),
                    fn (string $v): bool => $v !== '',
                )),
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Registered catalog %s → %s / %s',
            $result['key'],
            $result['service'],
            $result['group'],
        ));

        if ($result['wmis'] !== []) {
            $this->line('WMI: '.implode(', ', $result['wmis']));
        }

        return self::SUCCESS;
    }
}
