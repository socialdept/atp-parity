<?php

namespace SocialDept\AtpParity;

use Illuminate\Support\ServiceProvider;
use SocialDept\AtpParity\Commands\DiscoverCommand;
use SocialDept\AtpParity\Commands\ExportCommand;
use SocialDept\AtpParity\Commands\ImportCommand;
use SocialDept\AtpParity\Commands\ImportStatusCommand;
use SocialDept\AtpParity\Discovery\DiscoveryService;
use SocialDept\AtpParity\Export\ExportService;
use SocialDept\AtpParity\Import\ImportService;
use SocialDept\AtpParity\Publish\PublishService;
use SocialDept\AtpParity\Support\RecordHelper;

class ParityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerConfiguredMappers();

        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/parity.php', 'parity');

        $this->app->singleton(MapperRegistry::class);
        $this->app->alias(MapperRegistry::class, 'parity');

        $this->app->singleton(RecordHelper::class, function ($app) {
            return new RecordHelper($app->make(MapperRegistry::class));
        });

        $this->app->singleton(ImportService::class, function ($app) {
            return new ImportService($app->make(MapperRegistry::class));
        });

        $this->app->singleton(PublishService::class, function ($app) {
            return new PublishService($app->make(MapperRegistry::class));
        });

        $this->app->singleton(DiscoveryService::class, function ($app) {
            return new DiscoveryService($app->make(ImportService::class));
        });

        $this->app->singleton(ExportService::class, function ($app) {
            return new ExportService(
                $app->make(MapperRegistry::class),
                $app->make(ImportService::class)
            );
        });
    }

    /**
     * Register mappers defined in config.
     */
    protected function registerConfiguredMappers(): void
    {
        $registry = $this->app->make(MapperRegistry::class);

        foreach (config('parity.mappers', []) as $mapperClass) {
            if (class_exists($mapperClass)) {
                $registry->register($this->app->make($mapperClass));
            }
        }
    }

    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__.'/../config/parity.php' => config_path('parity.php'),
        ], 'parity-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'parity-migrations');

        $this->commands([
            DiscoverCommand::class,
            ExportCommand::class,
            ImportCommand::class,
            ImportStatusCommand::class,
        ]);
    }

    public function provides(): array
    {
        return [
            'parity',
            MapperRegistry::class,
            RecordHelper::class,
            ImportService::class,
            PublishService::class,
            DiscoveryService::class,
            ExportService::class,
        ];
    }
}
