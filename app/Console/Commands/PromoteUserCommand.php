<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

#[Description('Promote a user to the admin role (bootstrap / recovery path)')]
#[Signature('user:promote {email : The email of the user to promote to admin}')]
final class PromoteUserCommand extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error(sprintf('No user found with email [%s].', $email));

            return self::FAILURE;
        }

        (new RolesAndPermissionsSeeder)->run();

        $user->syncRoles([Permissions::RoleAdmin]);
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(sprintf('Promoted [%s] to admin.', $user->email));

        return self::SUCCESS;
    }
}
