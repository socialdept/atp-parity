<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;
use SocialDept\AtpParity\Events\ReferenceSynced;
use SocialDept\AtpParity\MapperRegistry;
use Throwable;

/**
 * Service for syncing reference records with their main records.
 *
 * Handles the coordination of syncing both main records (third-party lexicons)
 * and reference records (your platform's lexicons) together.
 */
class ReferenceSyncService
{
    public function __construct(
        protected MapperRegistry $registry,
        protected SyncService $syncService
    ) {}

    /**
     * Sync both main and reference records atomically.
     *
     * 1. Creates/updates main record first
     * 2. Uses returned uri+cid to create reference record
     * 3. If reference fails, optionally rolls back main record
     */
    public function syncWithReference(
        string $did,
        Model $model,
        ReferenceMapper $referenceMapper,
        ?bool $rollbackOnFailure = null
    ): ReferenceSyncResult {
        $rollbackOnFailure ??= config('parity.references.rollback_on_failure', true);

        $mainMapper = $referenceMapper->mainMapper();

        if (! $mainMapper) {
            return ReferenceSyncResult::failed(
                "No mapper registered for main lexicon: {$referenceMapper->mainLexicon()}"
            );
        }

        // Step 1: Sync main record using the main mapper
        $mainResult = $this->syncService->syncAsWithMapper($did, $model, $mainMapper);

        if ($mainResult->isFailed()) {
            return ReferenceSyncResult::failed($mainResult->error);
        }

        // Step 2: Create reference record
        $referenceResult = $this->syncReferenceOnly($did, $model, $referenceMapper);

        if ($referenceResult->isFailed() && $rollbackOnFailure) {
            // Rollback: delete the main record
            $this->syncService->unsync($model);

            return ReferenceSyncResult::failed(
                "Reference record failed: {$referenceResult->error}. Main record rolled back."
            );
        }

        if ($referenceResult->isFailed()) {
            // Return partial success - main synced but reference failed
            return ReferenceSyncResult::success(
                mainUri: $mainResult->uri,
                mainCid: $mainResult->cid,
                referenceUri: null,
                referenceCid: null
            );
        }

        return ReferenceSyncResult::success(
            mainUri: $mainResult->uri,
            mainCid: $mainResult->cid,
            referenceUri: $referenceResult->uri,
            referenceCid: $referenceResult->cid
        );
    }

    /**
     * Sync only the reference record pointing to an existing main record.
     *
     * The model must already have atp_uri (and optionally atp_cid) set
     * from a previously synced main record.
     */
    public function syncReferenceOnly(
        string $did,
        Model $model,
        ReferenceMapper $mapper
    ): SyncResult {
        // Verify main record exists
        $mainUri = $this->getMainUri($model);

        if (! $mainUri) {
            return SyncResult::failed(
                'Model must have main record synced before creating reference record'
            );
        }

        // Check if reference already synced
        $existingUri = $this->getReferenceUri($model, $mapper);
        if ($existingUri) {
            return $this->resyncReference($model, $mapper);
        }

        try {
            $record = $mapper->toRecord($model);
            $collection = $mapper->lexicon();

            $client = Atp::as($did);
            $response = $client->atproto->repo->createRecord(
                collection: $collection,
                record: $record->toArray(),
                validate: config('parity.sync.validate', true),
            );

            // Update model with reference record metadata
            $this->updateReferenceModelMeta($model, $mapper, $response->uri, $response->cid);

            event(new ReferenceSynced($model, $response->uri, $response->cid, $mainUri));

            return SyncResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            return SyncResult::failed($e->getMessage());
        }
    }

    /**
     * Sync reference record to an existing external main record.
     *
     * Use this when the main record was created elsewhere (third-party platform)
     * and you want to create a reference pointing to it.
     */
    public function syncReferenceToExternal(
        string $did,
        Model $model,
        ReferenceMapper $mapper,
        StrongRef $mainRef
    ): SyncResult {
        // Set the main reference on the model
        $this->setMainRefOnModel($model, $mainRef);

        return $this->syncReferenceOnly($did, $model, $mapper);
    }

    /**
     * Resync an existing reference record.
     */
    public function resyncReference(Model $model, ReferenceMapper $mapper): SyncResult
    {
        $uri = $this->getReferenceUri($model, $mapper);

        if (! $uri) {
            return SyncResult::failed('Reference record has not been synced yet.');
        }

        $parts = $this->parseUri($uri);
        if (! $parts) {
            return SyncResult::failed("Invalid AT Protocol URI: {$uri}");
        }

        try {
            $record = $mapper->toRecord($model);

            $client = Atp::as($parts['did']);
            $response = $client->atproto->repo->putRecord(
                collection: $parts['collection'],
                rkey: $parts['rkey'],
                record: $record->toArray(),
                validate: config('parity.sync.validate', true),
            );

            $this->updateReferenceModelMeta($model, $mapper, $response->uri, $response->cid);

            $mainUri = $this->getMainUri($model);
            event(new ReferenceSynced($model, $response->uri, $response->cid, $mainUri));

            return SyncResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            return SyncResult::failed($e->getMessage());
        }
    }

    /**
     * Unsync both reference and main records.
     *
     * Deletes reference first (to maintain referential integrity), then main.
     */
    public function unsyncWithReference(Model $model, ReferenceMapper $mapper): bool
    {
        // Delete reference first
        $this->unsyncReference($model, $mapper);

        // Then delete main
        return $this->syncService->unsync($model);
    }

    /**
     * Unsync only the reference record (keep main).
     */
    public function unsyncReference(Model $model, ReferenceMapper $mapper): bool
    {
        $uri = $this->getReferenceUri($model, $mapper);
        if (! $uri) {
            return false;
        }

        $parts = $this->parseUri($uri);
        if (! $parts) {
            return false;
        }

        try {
            $client = Atp::as($parts['did']);
            $client->atproto->repo->deleteRecord(
                collection: $parts['collection'],
                rkey: $parts['rkey'],
            );

            $this->clearReferenceModelMeta($model, $mapper);

            return true;
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Get the main record URI from model.
     */
    protected function getMainUri(Model $model): ?string
    {
        $column = config('parity.columns.uri', 'atp_uri');

        return $model->{$column};
    }

    /**
     * Get the reference record URI from model.
     */
    protected function getReferenceUri(Model $model, ReferenceMapper $mapper): ?string
    {
        return $model->{$mapper->referenceUriColumn()};
    }

    /**
     * Set the main record reference on the model.
     */
    protected function setMainRefOnModel(Model $model, StrongRef $ref): void
    {
        $uriColumn = config('parity.columns.uri', 'atp_uri');
        $cidColumn = config('parity.columns.cid', 'atp_cid');

        $model->{$uriColumn} = $ref->uri;
        if ($ref->cid) {
            $model->{$cidColumn} = $ref->cid;
        }
        $model->saveQuietly();
    }

    /**
     * Update model with reference record AT Protocol metadata.
     */
    protected function updateReferenceModelMeta(
        Model $model,
        ReferenceMapper $mapper,
        string $uri,
        string $cid
    ): void {
        $model->{$mapper->referenceUriColumn()} = $uri;
        $model->{$mapper->referenceCidColumn()} = $cid;
        $model->saveQuietly();
    }

    /**
     * Clear reference record AT Protocol metadata from model.
     */
    protected function clearReferenceModelMeta(Model $model, ReferenceMapper $mapper): void
    {
        $model->{$mapper->referenceUriColumn()} = null;
        $model->{$mapper->referenceCidColumn()} = null;
        $model->saveQuietly();
    }

    /**
     * Parse an AT Protocol URI into its components.
     *
     * @return array{did: string, collection: string, rkey: string}|null
     */
    protected function parseUri(string $uri): ?array
    {
        if (! preg_match('#^at://([^/]+)/([^/]+)/([^/]+)$#', $uri, $matches)) {
            return null;
        }

        return [
            'did' => $matches[1],
            'collection' => $matches[2],
            'rkey' => $matches[3],
        ];
    }

    /**
     * Determine if the exception should be caught and converted to a failed result.
     */
    protected function shouldCatchException(Throwable $e): bool
    {
        if ($e instanceof OAuthSessionInvalidException) {
            return false;
        }

        if ($e instanceof AuthenticationException) {
            return false;
        }

        return true;
    }
}
