<?php

namespace SocialDept\AtpParity\Publish;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Events\RecordPublished;
use SocialDept\AtpParity\Events\RecordUnpublished;
use SocialDept\AtpParity\MapperRegistry;
use Throwable;

/**
 * Service for publishing Eloquent models to AT Protocol.
 */
class PublishService
{
    public function __construct(
        protected MapperRegistry $registry
    ) {}

    /**
     * Publish a model as a new record to AT Protocol.
     *
     * Requires the model to have a DID association (via did column or relationship).
     */
    public function publish(Model $model): PublishResult
    {
        $did = $this->getDidFromModel($model);

        if (! $did) {
            return PublishResult::failed('No DID associated with model. Use publishAs() to specify a DID.');
        }

        return $this->publishAs($did, $model);
    }

    /**
     * Publish a model as a specific user.
     */
    public function publishAs(string $did, Model $model): PublishResult
    {
        $mapper = $this->registry->forModel(get_class($model));

        if (! $mapper) {
            return PublishResult::failed('No mapper registered for model: '.get_class($model));
        }

        // Check if already published
        $existingUri = $this->getModelUri($model);
        if ($existingUri) {
            return $this->update($model);
        }

        try {
            $record = $mapper->toRecord($model);
            $collection = $mapper->lexicon();

            $client = Atp::as($did);
            $response = $client->atproto->repo->createRecord(
                collection: $collection,
                record: $record->toArray(),
            );

            // Update model with ATP metadata
            $this->updateModelMeta($model, $response->uri, $response->cid);

            event(new RecordPublished($model, $response->uri, $response->cid));

            return PublishResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            return PublishResult::failed($e->getMessage());
        }
    }

    /**
     * Update an existing published record.
     */
    public function update(Model $model): PublishResult
    {
        $uri = $this->getModelUri($model);

        if (! $uri) {
            return PublishResult::failed('Model has not been published yet. Use publish() first.');
        }

        $mapper = $this->registry->forModel(get_class($model));

        if (! $mapper) {
            return PublishResult::failed('No mapper registered for model: '.get_class($model));
        }

        $parts = $this->parseUri($uri);

        if (! $parts) {
            return PublishResult::failed('Invalid AT Protocol URI: '.$uri);
        }

        try {
            $record = $mapper->toRecord($model);

            $client = Atp::as($parts['did']);
            $response = $client->atproto->repo->putRecord(
                collection: $parts['collection'],
                rkey: $parts['rkey'],
                record: $record->toArray(),
            );

            // Update model with new CID
            $this->updateModelMeta($model, $response->uri, $response->cid);

            event(new RecordPublished($model, $response->uri, $response->cid));

            return PublishResult::success($response->uri, $response->cid);
        } catch (Throwable $e) {
            return PublishResult::failed($e->getMessage());
        }
    }

    /**
     * Delete a published record from AT Protocol.
     */
    public function delete(Model $model): bool
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

            event(new RecordUnpublished($model, $uri));

            return true;
        } catch (Throwable $e) {
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

        $model->{$uriColumn} = $uri;
        $model->{$cidColumn} = $cid;
        $model->save();
    }

    /**
     * Clear AT Protocol metadata from model.
     */
    protected function clearModelMeta(Model $model): void
    {
        $uriColumn = config('parity.columns.uri', 'atp_uri');
        $cidColumn = config('parity.columns.cid', 'atp_cid');

        $model->{$uriColumn} = null;
        $model->{$cidColumn} = null;
        $model->save();
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
}
