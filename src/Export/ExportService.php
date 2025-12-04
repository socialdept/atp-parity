<?php

namespace SocialDept\AtpParity\Export;

use BackedEnum;
use Generator;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Import\ImportService;
use SocialDept\AtpParity\MapperRegistry;
use Throwable;

/**
 * Service for exporting AT Protocol repositories.
 */
class ExportService
{
    public function __construct(
        protected MapperRegistry $registry,
        protected ImportService $importService
    ) {}

    /**
     * Download a user's repository as CAR data.
     */
    public function downloadRepo(string $did, ?string $since = null): RepoExport
    {
        $response = Atp::public()->atproto->sync->getRepo($did, $since);
        $carData = $response->body();

        return new RepoExport(
            did: $did,
            carData: $carData,
            size: strlen($carData),
        );
    }

    /**
     * Export a repository to a local file.
     */
    public function exportToFile(string $did, string $path, ?string $since = null): ExportResult
    {
        try {
            $export = $this->downloadRepo($did, $since);

            if (! $export->saveTo($path)) {
                return ExportResult::failed("Failed to write to file: {$path}");
            }

            return ExportResult::success($path, $export->size);
        } catch (Throwable $e) {
            return ExportResult::failed($e->getMessage());
        }
    }

    /**
     * Export and import records from a repository.
     *
     * This downloads the repository and imports records using the normal import pipeline.
     * It's useful for bulk importing all records from a user.
     *
     * @param  array<string>|null  $collections  Specific collections to import (null = all registered)
     */
    public function exportAndImport(
        string $did,
        ?array $collections = null,
        ?callable $onProgress = null
    ): ExportResult {
        try {
            // Use the import service to import the user's records
            $result = $this->importService->importUser($did, $collections, $onProgress);

            if ($result->isFailed()) {
                return ExportResult::failed($result->error ?? 'Import failed');
            }

            return ExportResult::success(
                path: "imported:{$did}",
                size: $result->recordsSynced
            );
        } catch (Throwable $e) {
            return ExportResult::failed($e->getMessage());
        }
    }

    /**
     * List available blobs for a repository.
     *
     * @return Generator<string> Yields blob CIDs
     */
    public function listBlobs(string $did, ?string $since = null): Generator
    {
        $cursor = null;

        do {
            $response = Atp::public()->atproto->sync->listBlobs(
                did: $did,
                since: $since,
                limit: 500,
                cursor: $cursor,
            );

            foreach ($response->cids as $cid) {
                yield $cid;
            }

            $cursor = $response->cursor;
        } while ($cursor !== null);
    }

    /**
     * Download a specific blob.
     */
    public function downloadBlob(string $did, string $cid): string
    {
        $response = Atp::public()->atproto->sync->getBlob($did, $cid);

        return $response->body();
    }

    /**
     * Get the latest commit for a repository.
     */
    public function getLatestCommit(string $did): array
    {
        $commit = Atp::public()->atproto->sync->getLatestCommit($did);

        return [
            'cid' => $commit->cid,
            'rev' => $commit->rev,
        ];
    }

    /**
     * Get the hosting status for a repository.
     */
    public function getRepoStatus(string $did): array
    {
        $status = Atp::public()->atproto->sync->getRepoStatus($did);

        return $status->toArray();
    }
}
