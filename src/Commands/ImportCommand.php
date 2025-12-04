<?php

namespace SocialDept\AtpParity\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpParity\Events\ImportProgress;
use SocialDept\AtpParity\Import\ImportService;
use SocialDept\AtpParity\Jobs\ImportUserJob;
use SocialDept\AtpParity\MapperRegistry;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class ImportCommand extends Command
{
    protected $signature = 'parity:import
        {did? : The DID to import}
        {--collection= : Specific collection to import}
        {--file= : File containing DIDs to import (one per line)}
        {--resume : Resume all interrupted imports}
        {--queue : Queue the import job instead of running synchronously}
        {--progress : Show progress output}';

    protected $description = 'Import AT Protocol records for a user or from a file of DIDs';

    public function handle(ImportService $service, MapperRegistry $registry): int
    {
        if ($this->option('resume')) {
            return $this->handleResume($service);
        }

        $did = $this->argument('did');
        $file = $this->option('file');

        if (! $did && ! $file) {
            error('Please provide a DID or use --file to specify a file of DIDs');

            return self::FAILURE;
        }

        if ($file) {
            return $this->handleFile($file, $service);
        }

        return $this->importDid($did, $service, $registry);
    }

    protected function handleResume(ImportService $service): int
    {
        info('Resuming interrupted imports...');

        $results = $service->resumeAll($this->getProgressCallback());

        if (empty($results)) {
            note('No interrupted imports found');

            return self::SUCCESS;
        }

        $success = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result->isSuccess()) {
                $success++;
            } else {
                $failed++;
            }
        }

        info("Resumed {$success} imports successfully");

        if ($failed > 0) {
            warning("{$failed} imports failed");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function handleFile(string $file, ImportService $service): int
    {
        if (! file_exists($file)) {
            error("File not found: {$file}");

            return self::FAILURE;
        }

        $dids = array_filter(array_map('trim', file($file)));
        $total = count($dids);
        $success = 0;
        $failed = 0;

        info("Importing {$total} DIDs from {$file}");

        foreach ($dids as $index => $did) {
            if (! str_starts_with($did, 'did:')) {
                warning("Skipping invalid DID: {$did}");

                continue;
            }

            $current = $index + 1;
            note("[{$current}/{$total}] Importing {$did}");

            if ($this->option('queue')) {
                ImportUserJob::dispatch($did, $this->option('collection'));
                $success++;
            } else {
                $result = $service->importUser($did, $this->getCollections(), $this->getProgressCallback());

                if ($result->isSuccess()) {
                    $success++;
                } else {
                    $failed++;
                    warning("Failed: {$result->error}");
                }
            }
        }

        info("Completed: {$success} successful, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function importDid(string $did, ImportService $service, MapperRegistry $registry): int
    {
        if (! str_starts_with($did, 'did:')) {
            error("Invalid DID format: {$did}");

            return self::FAILURE;
        }

        $collections = $this->getCollections();
        $collectionDisplay = $collections ? implode(', ', $collections) : 'all registered';

        info("Importing {$did} ({$collectionDisplay})");

        if ($this->option('queue')) {
            ImportUserJob::dispatch($did, $this->option('collection'));
            note('Import job queued');

            return self::SUCCESS;
        }

        $result = $service->importUser($did, $collections, $this->getProgressCallback());

        if ($result->isSuccess()) {
            info("Import completed: {$result->recordsSynced} records synced");

            if ($result->recordsSkipped > 0) {
                note("{$result->recordsSkipped} records skipped");
            }

            if ($result->recordsFailed > 0) {
                warning("{$result->recordsFailed} records failed");
            }

            return self::SUCCESS;
        }

        error("Import failed: {$result->error}");

        if ($result->recordsSynced > 0) {
            note("Partial progress: {$result->recordsSynced} records synced before failure");
        }

        return self::FAILURE;
    }

    protected function getCollections(): ?array
    {
        $collection = $this->option('collection');

        return $collection ? [$collection] : null;
    }

    protected function getProgressCallback(): ?callable
    {
        if (! $this->option('progress')) {
            return null;
        }

        return function (ImportProgress $progress) {
            $this->output->write("\r");
            $this->output->write("  [{$progress->collection}] {$progress->recordsSynced} records synced");
        };
    }
}
