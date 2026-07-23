<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\CaptureXaiInferenceCost;
use App\Listeners\RecordIdentifyAgentToolProgress;
use App\Models\SearchRun;
use App\Models\User;
use App\Policies\SearchRunPolicy;
use App\Policies\UserPolicy;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use App\Services\PartsLink24\PartsLink24HttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            PartsLink24Catalog::class,
            PartsLink24HttpClient::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureInertiaSsr();
        $this->configureAiListeners();
        $this->configureAuthorization();
    }

    private function configureAuthorization(): void
    {
        // No Gate::before super-admin bypass — admin gets all permissions via seeder.
        Gate::policy(SearchRun::class, SearchRunPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }

    private function configureAiListeners(): void
    {
        Event::listen(InvokingTool::class, [RecordIdentifyAgentToolProgress::class, 'handleInvoking']);
        Event::listen(ToolInvoked::class, [RecordIdentifyAgentToolProgress::class, 'handleInvoked']);
        Event::listen(ResponseReceived::class, CaptureXaiInferenceCost::class);
    }

    /**
     * SSR is only for the public landing gate (`home`).
     * Authenticated operator UI (and auth pages) stay client-rendered.
     */
    private function configureInertiaSsr(): void
    {
        Inertia::disableSsr(fn (): bool => ! request()->routeIs('home'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8),
        );
    }
}
