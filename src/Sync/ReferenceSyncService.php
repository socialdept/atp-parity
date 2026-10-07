<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\Events\ReferenceSynced;
use SocialDept\AtpParity\Events\ReferenceSyncFailed;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\RecordMapper as AbstractRecordMapper;
use SocialDept\AtpParity\Support\MetaColumns;
use SocialDept\AtpParity\Support\RecordCid;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;
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
    ) {
    }

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
        ?bool $rollbackOnFailure = null,
        bool $force = false
    ): ReferenceSyncResult {
        $rollbackOnFailure ??= config('atp-parity.references.rollback_on_failure', true);

        $mainMapper = $referenceMapper->mainMapper();

        if (! $mainMapper) {
            return ReferenceSyncResult::failed(
                "No mapper registered for main lexicon: {$referenceMapper->mainLexicon()}"
            );
        }

        // INFO: only a main record this call created may be rolled back. One that
        // already existed is live content, and a failed reference is no reason to
        // delete it.
        $mainExisted = (bool) $this->getMainUri($model);

        // Step 1: Sync main record using the main mapper
        $mainResult = $this->syncService->syncAsWithMapper($did, $model, $mainMapper, $force);

        if ($mainResult->isFailed()) {
            return ReferenceSyncResult::failed($mainResult->error);
        }

        // Step 2: Create reference record
        $referenceResult = $this->syncReferenceOnly($did, $model, $referenceMapper, $force);

        if ($referenceResult->isFailed() && $rollbackOnFailure && ! $mainExisted) {
            // Rollback: delete the main record
            $this->syncService->unsync($model);

            return ReferenceSyncResult::failed(
                "Reference record failed: {$referenceResult->error}. Main record rolled back."
            );
        }

        if ($referenceResult->isFailed()) {
            // Main synced but reference failed: surface it instead of hiding it.
            return ReferenceSyncResult::referenceFailed(
                mainUri: $mainResult->uri,
                mainCid: $mainResult->cid,
                referenceUri: null,
                referenceError: $referenceResult->error ?? 'Reference record sync failed.',
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
        ReferenceMapper $mapper,
        bool $force = false
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
            return $this->resyncReference($model, $mapper, $force);
        }

        try {
            $record = $mapper->toRecord($model);
            $collection = $mapper->lexicon();

            $client = Atp::as($did);
            $response = $client->atproto->repo->createRecord(
                collection: $collection,
                record: $record->toArray(),
                rkey: method_exists($model, 'getDesiredAtpReferenceRkey') ? $model->getDesiredAtpReferenceRkey() : null,
                validate: config('atp-parity.sync.validate', true),
            );

            // Update model with reference record metadata
            $this->updateReferenceModelMeta($model, $mapper, $response->uri, $response->cid);

            event(new ReferenceSynced($model, $response->uri, $response->cid, $mainUri));

            return SyncResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            $this->reportReferenceFailure($model, null, $e);

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
     * Resync both main and reference records.
     *
     * Updates main record first, then reference record.
     * Tracks CID changes to know if records actually changed.
     *
     * `$force` writes both even when the repo already holds them, and must be
     * threaded to both halves: this is the entry point a repair tool reaches for,
     * so a force that stopped here would leave the pair unrepairable.
     */
    public function resyncWithReference(Model $model, ReferenceMapper $referenceMapper, bool $force = false): ReferenceSyncResult
    {
        $mainMapper = $referenceMapper->mainMapper();

        if (! $mainMapper) {
            return ReferenceSyncResult::failed(
                "No mapper registered for main lexicon: {$referenceMapper->mainLexicon()}"
            );
        }

        // Get current CIDs for comparison
        $oldMainCid = $model->{config('atp-parity.columns.cid', 'atp_cid')};
        $oldReferenceCid = $model->{$referenceMapper->referenceCidColumn()};

        // Step 1: Resync main record
        $mainResult = $this->syncService->resyncWithMapper($model, $mainMapper, $force);

        if ($mainResult->isFailed()) {
            return ReferenceSyncResult::failed($mainResult->error);
        }

        // Step 2: Resync reference record
        $referenceResult = $this->resyncReference($model, $referenceMapper, $force);

        if ($referenceResult->isFailed()) {
            // Main succeeded but reference failed: keep the (stale) reference uri
            // and surface the failure instead of reporting it as a clean success.
            return ReferenceSyncResult::referenceFailed(
                mainUri: $mainResult->uri,
                mainCid: $mainResult->cid,
                referenceUri: $model->{$referenceMapper->referenceUriColumn()},
                referenceError: $referenceResult->error ?? 'Reference record sync failed.',
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
     * Resync an existing reference record.
     */
    public function resyncReference(Model $model, ReferenceMapper $mapper, bool $force = false): SyncResult
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
            if (! $force && $mapper instanceof AbstractRecordMapper && $mapper->fieldMap()->overflowFields() !== []) {
                $draft = AbstractRecordMapper::withoutOverflowUploads(fn () => $mapper->toRecord($model));

                if ($unchanged = $this->referenceAlreadyInRepo($model, $mapper, $draft->toRecord())) {
                    return SyncResult::unchanged($uri, $unchanged);
                }
            }

            $record = $mapper->toRecord($model);

            // INFO: hash `toRecord()`, not `toArray()`. A PDS adds the top-level
            // `$type` before it hashes, so the CID we stored is of the record
            // including it. `toArray()` omits it, and comparing that form never
            // matches any record ever written: the guard reads as "changed" every
            // time and the skip never happens.
            if (! $force && $unchanged = $this->referenceAlreadyInRepo($model, $mapper, $record->toRecord())) {
                return SyncResult::unchanged($uri, $unchanged);
            }

            $client = Atp::as($parts['did']);
            $response = $client->atproto->repo->putRecord(
                collection: $parts['collection'],
                rkey: $parts['rkey'],
                record: $record->toArray(),
                validate: config('atp-parity.sync.validate', true),
            );

            $this->updateReferenceModelMeta($model, $mapper, $response->uri, $response->cid);

            $mainUri = $this->getMainUri($model);
            event(new ReferenceSynced($model, $response->uri, $response->cid, $mainUri));

            return SyncResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            $this->reportReferenceFailure($model, $uri, $e);

            return SyncResult::failed($e->getMessage());
        }
    }

    /**
     * Log and broadcast a reference-record write failure so it is never silent.
     */
    protected function reportReferenceFailure(Model $model, ?string $referenceUri, Throwable $e): void
    {
        Log::warning('atp-parity: reference record sync failed', [
            'model' => $model::class,
            'model_id' => $model->getKey(),
            'reference_uri' => $referenceUri,
            'error' => $e->getMessage(),
        ]);

        event(new ReferenceSyncFailed($model, $e->getMessage(), $referenceUri));
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
        $column = config('atp-parity.columns.uri', 'atp_uri');

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
        $uriColumn = config('atp-parity.columns.uri', 'atp_uri');
        $cidColumn = config('atp-parity.columns.cid', 'atp_cid');

        MetaColumns::write($model, $ref->cid
            ? [$uriColumn => $ref->uri, $cidColumn => $ref->cid]
            : [$uriColumn => $ref->uri]);
    }

    /**
     * The CID this reference record already has in the repo, or null to write.
     *
     * Reads the reference record's own CID column rather than the main record's.
     * The two are written separately, so sharing a column would let one suppress
     * the other's legitimate write.
     *
     * Null whenever the answer is not certain, so the caller writes. Only an
     * exact match may suppress a write.
     *
     * @param  array<string, mixed>  $record
     */
    protected function referenceAlreadyInRepo(Model $model, ReferenceMapper $mapper, array $record): ?string
    {
        if (! config('atp-parity.sync.skip_unchanged', true)) {
            return null;
        }

        $storedCid = $model->getAttribute($mapper->referenceCidColumn());

        if (! is_string($storedCid) || $storedCid === '') {
            return null;
        }

        return RecordCid::for($record) === $storedCid ? $storedCid : null;
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
        MetaColumns::write($model, [
            $mapper->referenceUriColumn() => $uri,
            $mapper->referenceCidColumn() => $cid,
        ]);
    }

    /**
     * Clear reference record AT Protocol metadata from model.
     */
    protected function clearReferenceModelMeta(Model $model, ReferenceMapper $mapper): void
    {
        MetaColumns::write($model, [
            $mapper->referenceUriColumn() => null,
            $mapper->referenceCidColumn() => null,
        ]);
    }

    /**
     * Parse an AT Protocol URI into its components.
     *
     * @return array{did: string, collection: string, rkey: string}|null
     */
    protected function parseUri(string $uri): ?array
    {
        $parsed = \SocialDept\AtpSupport\AtUri::parse($uri);

        if (! $parsed) {
            return null;
        }

        return [
            'did' => $parsed->did,
            'collection' => $parsed->collection,
            'rkey' => $parsed->rkey,
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
