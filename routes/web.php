<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DeleteUserController;
use App\Http\Controllers\Admin\ResendUserInviteController;
use App\Http\Controllers\Admin\RestoreUserController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\UpdateUserRoleController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\CancelIdentifyController;
use App\Http\Controllers\ExpandUnavailableFindingsController;
use App\Http\Controllers\IdentifyController;
use App\Http\Controllers\InvitePasswordController;
use App\Http\Controllers\PartSearchController;
use App\Http\Controllers\ProcurementAnalyticsController;
use App\Http\Controllers\ResumeIdentifyController;
use App\Http\Controllers\SearchRunFindingsController;
use App\Http\Controllers\ShowOePartDiagramController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return to_route('identify.create');
    }

    return Inertia::render('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('invite/set-password', [InvitePasswordController::class, 'create'])
        ->name('invite.set-password');
    Route::post('invite/set-password', [InvitePasswordController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invite.set-password.store');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::redirect('dashboard', '/identify')->name('dashboard');

    Route::get('parts', [PartSearchController::class, 'index'])
        ->middleware('permission:'.Permissions::PartsView)
        ->name('parts.index');
    Route::post('parts', [PartSearchController::class, 'store'])
        ->middleware(['permission:'.Permissions::PartsCreate, 'throttle:30,1'])
        ->name('parts.store');
    Route::get('parts/{run}', [PartSearchController::class, 'show'])
        ->middleware('permission:'.Permissions::PartsView)
        ->name('parts.show');

    Route::get('identify', [IdentifyController::class, 'create'])
        ->middleware('permission:'.Permissions::IdentifyView)
        ->name('identify.create');
    Route::post('identify', [IdentifyController::class, 'store'])
        ->middleware(['permission:'.Permissions::IdentifyCreate, 'throttle:10,1'])
        ->name('identify.store');
    Route::get('identify/{run}', [IdentifyController::class, 'show'])
        ->middleware('permission:'.Permissions::IdentifyView)
        ->name('identify.show');
    Route::get('identify/{run}/diagrams/{filename}', ShowOePartDiagramController::class)
        ->middleware('permission:'.Permissions::IdentifyView)
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('identify.diagram');
    Route::post('identify/{run}/resume', ResumeIdentifyController::class)
        ->middleware(['permission:'.Permissions::IdentifyCreate, 'throttle:30,1'])
        ->name('identify.resume');
    Route::post('identify/{run}/cancel', CancelIdentifyController::class)
        ->middleware(['permission:'.Permissions::IdentifyCreate, 'throttle:30,1'])
        ->name('identify.cancel');

    Route::get('search-runs/{run}/findings', SearchRunFindingsController::class)
        ->middleware('permission:'.Permissions::FindingsView)
        ->name('search-runs.findings.index');
    Route::post('search-runs/{run}/findings/unavailable', ExpandUnavailableFindingsController::class)
        ->middleware(['permission:'.Permissions::FindingsView, 'throttle:20,1'])
        ->name('search-runs.findings.unavailable');

    Route::get('analytics', ProcurementAnalyticsController::class)
        ->middleware('permission:'.Permissions::AnalyticsView)
        ->name('analytics.index');

    Route::middleware('permission:'.Permissions::AdminAccess)
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {
            Route::get('/', AdminDashboardController::class)->name('dashboard');

            Route::get('users', [AdminUserController::class, 'index'])
                ->middleware('permission:'.Permissions::UsersView)
                ->name('users.index');
            Route::post('users', [AdminUserController::class, 'store'])
                ->middleware(['permission:'.Permissions::UsersManage, 'throttle:20,1'])
                ->name('users.store');
            Route::post('users/{user}/resend-invite', ResendUserInviteController::class)
                ->middleware(['permission:'.Permissions::UsersManage, 'throttle:10,1'])
                ->name('users.resend-invite');
            Route::put('users/{user}/role', UpdateUserRoleController::class)
                ->middleware('permission:'.Permissions::UsersManage)
                ->name('users.update-role');
            Route::delete('users/{user}', DeleteUserController::class)
                ->middleware('permission:'.Permissions::UsersManage)
                ->name('users.destroy');
            Route::post('users/{user}/restore', RestoreUserController::class)
                ->middleware('permission:'.Permissions::UsersManage)
                ->withTrashed()
                ->name('users.restore');

            Route::get('roles', [AdminRoleController::class, 'index'])
                ->middleware('permission:'.Permissions::RolesView)
                ->name('roles.index');
            Route::put('roles/{role}', [AdminRoleController::class, 'update'])
                ->middleware('permission:'.Permissions::RolesManage)
                ->name('roles.update');
        });
});

require __DIR__.'/settings.php';
