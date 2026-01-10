<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpParity\Blob\BlobDownloader;
use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\Blob\BlobUploader;
use SocialDept\AtpParity\Commands\DiscoverCommand;
use SocialDept\AtpParity\Commands\ExportCommand;
use SocialDept\AtpParity\Commands\ImportCommand;
use SocialDept\AtpParity\Commands\ImportStatusCommand;
use SocialDept\AtpParity\Commands\MakeMapperCommand;
use SocialDept\AtpParity\Contracts\BlobStorage;
use SocialDept\AtpParity\Discovery\DiscoveryService;
use SocialDept\AtpParity\Export\ExportService;
use SocialDept\AtpParity\Import\ImportService;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Storage\FilesystemBlobStorage;
use SocialDept\AtpParity\Support\RecordHelper;

class ParityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerBlueprintMacros();
        $this->registerConfiguredMappers();

        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register Blueprint macros for ATP columns.
     */
    protected function registerBlueprintMacros(): void
    {
        /**
         * Add AT Protocol record columns.
         *
         * Adds: atp_uri, atp_cid, atp_synced_at
         *
         * @return void
         */
        Blueprint::macro('atp', function () {
            /** @var Blueprint $this */
            $uriColumn = config('parity.columns.uri', 'atp_uri');
            $cidColumn = config('parity.columns.cid', 'atp_cid');
            $syncedAtColumn = config('parity.columns.synced_at', 'atp_synced_at');

            $this->string($uriColumn)->nullable()->unique();
            $this->string($cidColumn)->nullable();
            $this->timestamp($syncedAtColumn)->nullable();
        });

        /**
         * Add AT Protocol reference record columns.
         *
         * Adds: atp_reference_uri, and optionally atp_reference_cid
         *
         * @param  bool  $includeCid  Whether to include the CID column (default: true)
         * @return void
         */
        Blueprint::macro('atpReference', function (bool $includeCid = true) {
            /** @var Blueprint $this */
            $uriColumn = config('parity.references.columns.reference_uri', 'atp_reference_uri');
            $cidColumn = config('parity.references.columns.reference_cid', 'atp_reference_cid');

            $this->string($uriColumn)->nullable()->unique();

            if ($includeCid) {
                $this->string($cidColumn)->nullable();
            }
        });

        /**
         * Drop AT Protocol record columns.
         *
         * Drops the unique index first to handle SQLite limitations.
         *
         * @return void
         */
        Blueprint::macro('dropAtp', function () {
            /** @var Blueprint $this */
            $uriColumn = config('parity.columns.uri', 'atp_uri');
            $cidColumn = config('parity.columns.cid', 'atp_cid');
            $syncedAtColumn = config('parity.columns.synced_at', 'atp_synced_at');

            // Drop unique index first (required for SQLite)
            $this->dropUnique([$uriColumn]);
            $this->dropColumn([$uriColumn, $cidColumn, $syncedAtColumn]);
        });

        /**
         * Drop AT Protocol reference record columns.
         *
         * Drops the unique index first to handle SQLite limitations.
         *
         * @param  bool  $includeCid  Whether the CID column exists (default: true)
         * @return void
         */
        Blueprint::macro('dropAtpReference', function (bool $includeCid = true) {
            /** @var Blueprint $this */
            $uriColumn = config('parity.references.columns.reference_uri', 'atp_reference_uri');
            $cidColumn = config('parity.references.columns.reference_cid', 'atp_reference_cid');

            // Drop unique index first (required for SQLite)
            $this->dropUnique([$uriColumn]);

            $columns = [$uriColumn];

            if ($includeCid) {
                $columns[] = $cidColumn;
            }

            $this->dropColumn($columns);
        });
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

        $this->app->singleton(SyncService::class, function ($app) {
            return new SyncService($app->make(MapperRegistry::class));
        });

        $this->app->singleton(ReferenceSyncService::class, function ($app) {
            return new ReferenceSyncService(
                $app->make(MapperRegistry::class),
                $app->make(SyncService::class)
            );
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

        $this->registerBlobServices();
    }

    /**
     * Register blob-related services.
     */
    protected function registerBlobServices(): void
    {
        $this->app->singleton(BlobStorage::class, function () {
            return new FilesystemBlobStorage(
                config('parity.blobs.disk'),
                config('parity.blobs.path', 'atp-blobs')
            );
        });

        $this->app->singleton(BlobDownloader::class, function ($app) {
            return new BlobDownloader($app->make(BlobStorage::class));
        });

        $this->app->singleton(BlobUploader::class, function ($app) {
            return new BlobUploader($app->make(BlobStorage::class));
        });

        $this->app->singleton(BlobManager::class, function ($app) {
            return new BlobManager(
                $app->make(BlobStorage::class),
                $app->make(BlobDownloader::class),
                $app->make(BlobUploader::class)
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

        $this->publishes([
            __DIR__.'/../stubs/mapper.stub' => base_path('stubs/atp-mapper.stub'),
        ], 'parity-stubs');

        $this->commands([
            DiscoverCommand::class,
            ExportCommand::class,
            ImportCommand::class,
            ImportStatusCommand::class,
            MakeMapperCommand::class,
        ]);
    }

    public function provides(): array
    {
        return [
            'parity',
            MapperRegistry::class,
            RecordHelper::class,
            ImportService::class,
            SyncService::class,
            ReferenceSyncService::class,
            DiscoveryService::class,
            ExportService::class,
            BlobStorage::class,
            BlobDownloader::class,
            BlobUploader::class,
            BlobManager::class,
        ];
    }
}
