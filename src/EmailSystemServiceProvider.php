<?php

namespace JanDev\EmailSystem;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Observers\AudienceUserObserver;

class EmailSystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/email-system.php',
            'email-system'
        );
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/email-system.php' => config_path('email-system.php'),
        ], 'email-system-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'email-system-migrations');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/email-system'),
        ], 'email-system-views');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'email-system');

        // Load JSON translations (merged with app translations)
        $this->loadJsonTranslationsFrom(__DIR__ . '/../resources/lang');

        // Register observers
        AudienceUser::observe(AudienceUserObserver::class);

        // Register routes with configured prefix and middleware
        $this->registerRoutes();

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\EmailDuplicateWatchdog::class,
                Console\Commands\FixEmailStatusFromMailgun::class,
                Console\Commands\SyncMailgunSuppressions::class,
                Console\Commands\CleanupMailgunEvents::class,
                Console\Commands\SyncCustomFieldIndexes::class,
                Console\Commands\PmtaSync::class,
                Console\Commands\FinalizeCampaigns::class,
                Console\Commands\DispatchScheduledCampaigns::class,
                Console\Commands\MigrateOemproLists::class,
                Console\Commands\ImportOemproSuppressions::class,
                Console\Commands\PrunePmtaStats::class,
                Console\Commands\BackfillPmtaStats::class,
                Console\Commands\PmtaBounceSummary::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        $prefix = config('email-system.routes.prefix', 'email-system');
        $webMiddleware = config('email-system.routes.middleware', ['web']);
        $apiMiddleware = config('email-system.routes.webhook_middleware', ['api']);

        Route::middleware($webMiddleware)
            ->prefix($prefix)
            ->group(__DIR__ . '/../routes/web.php');

        Route::middleware($apiMiddleware)
            ->prefix($prefix)
            ->group(__DIR__ . '/../routes/api.php');
    }
}
