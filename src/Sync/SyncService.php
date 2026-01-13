<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Events\RecordSynced;
use SocialDept\AtpParity\Events\RecordUnsynced;
use SocialDept\AtpParity\MapperRegistry;
use Throwable;

/**
 * Service for syncing Eloquent models to AT Protocol.
 */
class SyncService
{
    public function __construct(
        protected MapperRegistry $registry
    ) {}

    /**
     * Sync a model as a new record to AT Protocol.
     *
     * Requires the model to have a DID association (via did column or relationship).
     */
    public function sync(Model $model): SyncResult
    {
        $did = $this->getDidFromModel($model);

        if (! $did) {
            return SyncResult::failed('No DID associated with model. Use syncAs() to specify a DID.');
        }

        return $this->syncAs($did, $model);
    }

    /**
     * Sync a model as a specific user.
     */
    public function syncAs(string $did, Model $model): SyncResult
    {
        $mapper = $this->registry->forModel(get_class($model));

        if (! $mapper) {
            return SyncResult::failed('No mapper registered for model: '.get_class($model));
        }

        return $this->syncAsWithMapper($did, $model, $mapper);
    }

    /**
     * Sync a model as a specific user with an explicit mapper.
     *
     * Use this when a model has multiple mappers (e.g., main + reference records).
     */
    public function syncAsWithMapper(string $did, Model $model, \SocialDept\AtpParity\Contracts\RecordMapper $mapper): SyncResult
    {
        // Check if already synced
        $existingUri = $this->getModelUri($model);
        if ($existingUri) {
            return $this->resyncWithMapper($model, $mapper);
        }

        try {
            $record = $mapper->toRecord($model);
            $collection = $mapper->lexicon();

            $client = Atp::as($did);
            $response = $client->atproto->repo->createRecord(
                collection: $collection,
                record: $record->toArray(),
                rkey: method_exists($model, 'getDesiredAtpRkey') ? $model->getDesiredAtpRkey() : null,
                validate: config('parity.sync.validate', true),
            );

            // Update model with ATP metadata
            $this->updateModelMeta($model, $response->uri, $response->cid);

            event(new RecordSynced($model, $response->uri, $response->cid));

            return SyncResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            return SyncResult::failed($e->getMessage());
        }
    }

    /**
     * Resync an existing synced record.
     */
    public function resync(Model $model): SyncResult
    {
        $mapper = $this->registry->forModel(get_class($model));

        if (! $mapper) {
            return SyncResult::failed('No mapper registered for model: '.get_class($model));
        }

        return $this->resyncWithMapper($model, $mapper);
    }

    /**
     * Resync an existing synced record with an explicit mapper.
     */
    public function resyncWithMapper(Model $model, \SocialDept\AtpParity\Contracts\RecordMapper $mapper): SyncResult
    {
        $uri = $this->getModelUri($model);

        if (! $uri) {
            return SyncResult::failed('Model has not been synced yet. Use sync() first.');
        }

        $parts = $this->parseUri($uri);

        if (! $parts) {
            return SyncResult::failed('Invalid AT Protocol URI: '.$uri);
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

            // Update model with new CID
            $this->updateModelMeta($model, $response->uri, $response->cid);

            event(new RecordSynced($model, $response->uri, $response->cid));

            return SyncResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            return SyncResult::failed($e->getMessage());
        }
    }

    /**
     * Unsync (delete) a record from AT Protocol.
     */
    public function unsync(Model $model): bool
    {
        $uri = $this->getModelUri($model);

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

            // Clear ATP metadata from model
            $this->clearModelMeta($model);

            event(new RecordUnsynced($model, $uri));

            return true;
        } catch (Throwable $e) {
            if (! $this->shouldCatchException($e)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Get the DID from a model.
     *
     * Override this method or set a did column/relationship on your model.
     */
    protected function getDidFromModel(Model $model): ?string
    {
        // Check for did column
        if (isset($model->did)) {
            return $model->did;
        }

        // Check for user relationship with did
        if (method_exists($model, 'user') && $model->user?->did) {
            return $model->user->did;
        }

        // Check for author relationship with did
        if (method_exists($model, 'author') && $model->author?->did) {
            return $model->author->did;
        }

        // Try extracting from existing URI
        $uri = $this->getModelUri($model);
        if ($uri) {
            $parts = $this->parseUri($uri);

            return $parts['did'] ?? null;
        }

        return null;
    }

    /**
     * Get the AT Protocol URI from a model.
     */
    protected function getModelUri(Model $model): ?string
    {
        $column = config('parity.columns.uri', 'atp_uri');

        return $model->{$column};
    }

    /**
     * Update model with AT Protocol metadata.
     */
    protected function updateModelMeta(Model $model, string $uri, string $cid): void
    {
        $uriColumn = config('parity.columns.uri', 'atp_uri');
        $cidColumn = config('parity.columns.cid', 'atp_cid');
        $syncedAtColumn = config('parity.columns.synced_at', 'atp_synced_at');

        $model->{$uriColumn} = $uri;
        $model->{$cidColumn} = $cid;
        $model->{$syncedAtColumn} = now();
        $model->saveQuietly();
    }

    /**
     * Clear AT Protocol metadata from model.
     */
    protected function clearModelMeta(Model $model): void
    {
        $uriColumn = config('parity.columns.uri', 'atp_uri');
        $cidColumn = config('parity.columns.cid', 'atp_cid');
        $syncedAtColumn = config('parity.columns.synced_at', 'atp_synced_at');

        $model->{$uriColumn} = null;
        $model->{$cidColumn} = null;
        $model->{$syncedAtColumn} = null;
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
     *
     * Override this method to customize which exceptions propagate vs. are caught.
     * By default, authentication-related exceptions are propagated to allow
     * the application to handle re-authentication flows.
     */
    protected function shouldCatchException(Throwable $e): bool
    {
        // Authentication exceptions should propagate so the app can handle reauth
        if ($e instanceof OAuthSessionInvalidException) {
            return false;
        }

        if ($e instanceof AuthenticationException) {
            return false;
        }

        // All other exceptions are caught and converted to failed results
        return true;
    }
}
