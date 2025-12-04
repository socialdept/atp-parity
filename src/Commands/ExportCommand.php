<?php

namespace SocialDept\AtpParity\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpParity\Events\ImportProgress;
use SocialDept\AtpParity\Export\ExportService;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class ExportCommand extends Command
{
    protected $signature = 'parity:export
        {did : The DID to export}
        {--output= : Output CAR file path}
        {--import : Import records to database instead of saving CAR file}
        {--collection=* : Specific collections to import (with --import)}
        {--since= : Only export changes since this revision}
        {--status : Show repository status instead of exporting}';

    protected $description = 'Export an AT Protocol repository as CAR file or import to database';

    public function handle(ExportService $service): int
    {
        $did = $this->argument('did');

        if (! str_starts_with($did, 'did:')) {
            error("Invalid DID format: {$did}");

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->handleStatus($service, $did);
        }

        if ($this->option('import')) {
            return $this->handleImport($service, $did);
        }

        return $this->handleExport($service, $did);
    }

    protected function handleStatus(ExportService $service, string $did): int
    {
        info("Getting repository status for {$did}...");

        try {
            $commit = $service->getLatestCommit($did);
            $status = $service->getRepoStatus($did);

            $this->table(['Property', 'Value'], [
                ['DID', $did],
                ['Latest CID', $commit['cid'] ?? 'N/A'],
                ['Latest Rev', $commit['rev'] ?? 'N/A'],
                ['Active', ($status['active'] ?? false) ? 'Yes' : 'No'],
                ['Status', $status['status'] ?? 'N/A'],
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error("Failed to get status: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function handleExport(ExportService $service, string $did): int
    {
        $output = $this->option('output') ?? "{$did}.car";
        $since = $this->option('since');

        // Sanitize filename if using DID as filename
        $output = str_replace([':', '/'], ['_', '_'], $output);

        info("Exporting repository {$did} to {$output}...");

        $result = $service->exportToFile($did, $output, $since);

        if ($result->isFailed()) {
            error("Export failed: {$result->error}");

            return self::FAILURE;
        }

        $size = $this->formatBytes($result->size);
        info("Exported {$size} to {$output}");

        return self::SUCCESS;
    }

    protected function handleImport(ExportService $service, string $did): int
    {
        $collections = $this->option('collection') ?: null;
        $collectionDisplay = $collections ? implode(', ', $collections) : 'all registered';

        info("Exporting and importing {$did} ({$collectionDisplay})...");

        $result = $service->exportAndImport(
            $did,
            $collections,
            function (ImportProgress $progress) {
                $this->output->write("\r");
                $this->output->write("  [{$progress->collection}] {$progress->recordsSynced} records synced");
            }
        );

        $this->output->write("\n");

        if ($result->isFailed()) {
            error("Import failed: {$result->error}");

            return self::FAILURE;
        }

        info("Imported {$result->size} records");

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2).' '.$units[$unit];
    }
}
