<?php

namespace SocialDept\AtpParity\Import;

use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Events\ImportCompleted;
use SocialDept\AtpParity\Events\ImportFailed;
use SocialDept\AtpParity\Events\ImportProgress;
use SocialDept\AtpParity\Events\ImportStarted;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpResolver\Facades\Resolver;
use Throwable;

/**
 * Orchestrates importing of AT Protocol records to Eloquent models.
 *
 * Supports importing individual users, specific collections, or entire
 * networks through cursor-based pagination with progress tracking.
 */
class ImportService
{
    /**
     * Cache of clients by PDS endpoint.
     *
     * @var array<string, AtpClient>
     */
    protected array $clients = [];

    public function __construct(
        protected MapperRegistry $registry
    ) {}

    /**
     * Import all records for a user in registered collections.
     *
     * @param  array<string>|null  $collections  Specific collections to import, or null for all registered
     */
    public function importUser(string $did, ?array $collections = null, ?callable $onProgress = null): ImportResult
    {
        $collections = $collections ?? $this->registry->lexicons();
        $results = [];

        foreach ($collections as $collection) {
            if (! $this->registry->hasLexicon($collection)) {
                continue;
            }

            $results[] = $this->importUserCollection($did, $collection, $onProgress);
        }

        return ImportResult::aggregate($did, $results);
    }

    /**
     * Import a specific collection for a user.
     */
    public function importUserCollection(string $did, string $collection, ?callable $onProgress = null): ImportResult
    {
        $mapper = $this->registry->forLexicon($collection);

        if (! $mapper) {
            return ImportResult::failed($did, $collection, "No mapper registered for collection: {$collection}");
        }

        $state = ImportState::findOrCreateFor($did, $collection);

        if ($state->isComplete()) {
            return $state->toResult();
        }

        $pdsEndpoint = $this->resolvePds($did);

        if (! $pdsEndpoint) {
            $error = "Could not resolve PDS endpoint for DID: {$did}";
            $state->markFailed($error);
            event(new ImportFailed($did, $collection, $error));

            return ImportResult::failed($did, $collection, $error);
        }

        $state->markStarted();
        event(new ImportStarted($did, $collection));

        $client = $this->clientFor($pdsEndpoint);
        $cursor = $state->cursor;
        $pageSize = config('parity.import.page_size', 100);
        $pageDelay = config('parity.import.page_delay', 100);
        $recordClass = $mapper->recordClass();

        try {
            do {
                $response = $client->atproto->repo->listRecords(
                    repo: $did,
                    collection: $collection,
                    limit: $pageSize,
                    cursor: $cursor
                );

                $synced = 0;
                $skipped = 0;
                $failed = 0;

                foreach ($response->records as $item) {
                    try {
                        $record = $recordClass::fromArray($item['value']);

                        $mapper->upsert($record, [
                            'uri' => $item['uri'],
                            'cid' => $item['cid'],
                        ]);

                        $synced++;
                    } catch (Throwable $e) {
                        $failed++;
                    }
                }

                $cursor = $response->cursor;
                $state->updateProgress($synced, $skipped, $failed, $cursor);

                if ($onProgress) {
                    $onProgress(new ImportProgress(
                        did: $did,
                        collection: $collection,
                        recordsSynced: $state->records_synced,
                        cursor: $cursor
                    ));
                }

                event(new ImportProgress($did, $collection, $state->records_synced, $cursor));

                if ($cursor && $pageDelay > 0) {
                    usleep($pageDelay * 1000);
                }
            } while ($cursor);

            $state->markCompleted();
            $result = $state->toResult();
            event(new ImportCompleted($result));

            return $result;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $state->markFailed($error);
            event(new ImportFailed($did, $collection, $error));

            return ImportResult::failed(
                did: $did,
                collection: $collection,
                error: $error,
                synced: $state->records_synced,
                skipped: $state->records_skipped,
                failed: $state->records_failed,
                cursor: $state->cursor
            );
        }
    }

    /**
     * Resume an interrupted import from cursor.
     */
    public function resume(ImportState $state, ?callable $onProgress = null): ImportResult
    {
        if (! $state->canResume()) {
            return $state->toResult();
        }

        $state->update(['status' => ImportState::STATUS_PENDING]);

        return $this->importUserCollection($state->did, $state->collection, $onProgress);
    }

    /**
     * Resume all interrupted imports.
     *
     * @return array<ImportResult>
     */
    public function resumeAll(?callable $onProgress = null): array
    {
        $results = [];

        ImportState::resumable()->each(function (ImportState $state) use (&$results, $onProgress) {
            $results[] = $this->resume($state, $onProgress);
        });

        return $results;
    }

    /**
     * Get import status for a DID/collection.
     */
    public function getStatus(string $did, string $collection): ?ImportState
    {
        return ImportState::where('did', $did)
            ->where('collection', $collection)
            ->first();
    }

    /**
     * Get all import states for a DID.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ImportState>
     */
    public function getStatusForUser(string $did): \Illuminate\Database\Eloquent\Collection
    {
        return ImportState::where('did', $did)->get();
    }

    /**
     * Check if a user's collection has been imported.
     */
    public function isImported(string $did, string $collection): bool
    {
        $state = $this->getStatus($did, $collection);

        return $state && $state->isComplete();
    }

    /**
     * Reset an import state to allow re-importing.
     */
    public function reset(string $did, string $collection): void
    {
        ImportState::where('did', $did)
            ->where('collection', $collection)
            ->delete();
    }

    /**
     * Reset all import states for a user.
     */
    public function resetUser(string $did): void
    {
        ImportState::where('did', $did)->delete();
    }

    /**
     * Get or create a client for a PDS endpoint.
     */
    protected function clientFor(string $pdsEndpoint): AtpClient
    {
        return $this->clients[$pdsEndpoint] ??= Atp::public($pdsEndpoint);
    }

    /**
     * Resolve the PDS endpoint for a DID.
     */
    protected function resolvePds(string $did): ?string
    {
        return Resolver::resolvePds($did);
    }
}
