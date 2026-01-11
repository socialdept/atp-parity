<?php

namespace SocialDept\AtpParity\Signals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\ConflictDetector;
use SocialDept\AtpParity\Sync\ConflictResolver;
use SocialDept\AtpParity\Enums\ValidationMode;
use SocialDept\AtpParity\Sync\ConflictStrategy;
use SocialDept\AtpSchema\Data\Data;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Signals\Signal;

/**
 * Signal that automatically syncs firehose events to Eloquent models.
 *
 * This signal listens for commit events on collections that have registered
 * mappers and automatically creates, updates, or deletes the corresponding
 * Eloquent models.
 *
 * Supports selective sync via configuration or by extending this class:
 * - Filter by DID: config('parity.sync.dids') or override dids()
 * - Filter by operation: config('parity.sync.operations') or override operations()
 * - Custom filter: config('parity.sync.filter') or override shouldSync()
 *
 * Supports conflict resolution via configuration:
 * - Strategy: config('parity.conflicts.strategy') - 'remote', 'local', 'newest', 'manual'
 *
 * To use this signal, register it in your atp-signals config:
 *
 * // config/signal.php
 * return [
 *     'signals' => [
 *         \SocialDept\AtpParity\Signals\ParitySignal::class,
 *     ],
 * ];
 */
class ParitySignal extends Signal
{
    protected ConflictDetector $conflictDetector;

    protected ConflictResolver $conflictResolver;

    public function __construct(
        protected MapperRegistry $registry
    ) {
        $this->conflictDetector = new ConflictDetector;
        $this->conflictResolver = new ConflictResolver;
    }

    /**
     * Listen for commit events only.
     */
    public function eventTypes(): array
    {
        return ['commit'];
    }

    /**
     * Only listen for collections that have registered mappers.
     */
    public function collections(): ?array
    {
        $lexicons = $this->registry->lexicons();

        // Return null if no mappers registered (don't match anything)
        return empty($lexicons) ? ['__none__'] : $lexicons;
    }

    /**
     * Get the DIDs to sync (null = all DIDs).
     *
     * Override this method for custom DID filtering logic.
     */
    public function dids(): ?array
    {
        return config('parity.sync.dids');
    }

    /**
     * Get the operations to sync (null = all operations).
     *
     * Possible values: 'create', 'update', 'delete'
     * Override this method for custom operation filtering.
     */
    public function operations(): ?array
    {
        return config('parity.sync.operations');
    }

    /**
     * Determine if the event should be synced.
     *
     * Override this method for custom filtering logic.
     */
    public function shouldSync(SignalEvent $event): bool
    {
        // Check custom filter callback from config
        $filter = config('parity.sync.filter');
        if ($filter && is_callable($filter)) {
            return $filter($event);
        }

        return true;
    }

    /**
     * Handle the firehose event.
     */
    public function handle(SignalEvent $event): void
    {
        if (! $event->commit) {
            $this->debug('Skipping: no commit event', $event);

            return;
        }

        // Apply DID filter
        $dids = $this->dids();
        if ($dids !== null && ! in_array($event->did, $dids)) {
            $this->debug('Skipping: DID not in allowed list', $event, ['allowed_dids' => $dids]);

            return;
        }

        $commit = $event->commit;

        // Apply operation filter
        $operations = $this->operations();
        if ($operations !== null) {
            $operation = $this->getOperationType($commit);
            if (! in_array($operation, $operations)) {
                $this->debug('Skipping: operation not allowed', $event, [
                    'allowed_operations' => $operations,
                ]);

                return;
            }
        }

        // Apply custom filter
        if (! $this->shouldSync($event)) {
            $this->debug('Skipping: shouldSync returned false', $event);

            return;
        }

        $mapper = $this->registry->forLexicon($commit->collection);

        if (! $mapper) {
            $this->debug('Skipping: no mapper found for collection', $event);

            return;
        }

        $this->debug('Processing event', $event, ['mapper' => get_class($mapper)]);

        try {
            if ($commit->isCreate() || $commit->isUpdate()) {
                $this->handleUpsert($event, $mapper);
            } elseif ($commit->isDelete()) {
                $this->handleDelete($event, $mapper);
            }
        } catch (\Throwable $e) {
            Log::error('ParitySignal: Error processing event', [
                'did' => $event->did,
                'collection' => $commit->collection,
                'operation' => $commit->operation?->value ?? null,
                'rkey' => $commit->rkey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to let EventDispatcher handle it
        }
    }

    /**
     * Get the operation type from a commit.
     */
    protected function getOperationType(object $commit): string
    {
        if ($commit->isCreate()) {
            return 'create';
        }

        if ($commit->isUpdate()) {
            return 'update';
        }

        if ($commit->isDelete()) {
            return 'delete';
        }

        return 'unknown';
    }

    /**
     * Handle create or update operations.
     */
    protected function handleUpsert(SignalEvent $event, RecordMapper $mapper): void
    {
        $commit = $event->commit;

        if (! $commit->record) {
            $this->debug('Skipping upsert: record is empty', $event);

            return;
        }

        $recordClass = $mapper->recordClass();

        // Ensure record class extends Data (required for validation)
        if (! is_subclass_of($recordClass, Data::class)) {
            $this->debug('Skipping upsert: record class does not extend Data', $event, [
                'record_class' => $recordClass,
            ]);

            return;
        }

        // Get validation mode early - needed to decide how to handle fromArray() failures
        $validationMode = $this->getValidationMode($mapper);

        // Try to create the record - may fail if data is malformed
        try {
            $record = $recordClass::fromArray((array) $commit->record);
        } catch (\Throwable $e) {
            // Validation disabled - re-throw the exception
            if (! $validationMode || $validationMode === ValidationMode::Disabled) {
                throw $e;
            }

            // Validation enabled - treat construction failures as validation failures
            if (config('parity.validation.log_failures', true)) {
                Log::warning('ParitySignal: Record failed to construct', [
                    'did' => $event->did,
                    'collection' => $commit->collection,
                    'rkey' => $commit->rkey,
                    'validation_mode' => $validationMode->value,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->debug('Skipping upsert: record failed to construct', $event, [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Validate record against lexicon schema if validation is enabled
        if (! $this->validateRecord($record, $mapper, $event)) {
            return;
        }

        $uri = $this->buildUri($event->did, $commit->collection, $commit->rkey);
        $meta = [
            'uri' => $uri,
            'cid' => $commit->cid,
            'did' => $event->did,
            'rkey' => $commit->rkey,
        ];

        // Check for existing model and potential conflict
        $existing = $mapper->findByUri($uri);

        // Capture existing blob CIDs before any changes
        $existingBlobs = $existing?->getAttribute('atp_blobs');

        if ($existing && $this->conflictDetector->hasConflict($existing, $record, $commit->cid)) {
            $strategy = ConflictStrategy::fromConfig();
            $resolution = $this->conflictResolver->resolve(
                $existing,
                $record,
                $meta,
                $mapper,
                $strategy
            );

            // If conflict is pending manual resolution, don't apply changes
            if (! $resolution->isResolved()) {
                $this->debug('Skipping upsert: conflict pending manual resolution', $event);

                return;
            }

            $this->debug('Conflict resolved', $event, ['resolution' => $resolution->winner]);

            // Sync blobs if changed during conflict resolution
            $this->syncBlobsIfChanged($existing, $mapper, $existingBlobs, $event->did);

            return;
        }

        // No conflict, proceed with normal upsert
        $result = $mapper->upsert($record, $meta);

        if ($result === null) {
            $this->debug('Skipping upsert: shouldImport returned false', $event);
        } else {
            $this->debug('Upsert successful', $event, ['model_id' => $result->getKey()]);

            // Sync blobs to MediaLibrary if changed
            $this->syncBlobsIfChanged($result, $mapper, $existingBlobs, $event->did);
        }
    }

    /**
     * Handle delete operations.
     */
    protected function handleDelete(SignalEvent $event, RecordMapper $mapper): void
    {
        $commit = $event->commit;
        $uri = $this->buildUri($event->did, $commit->collection, $commit->rkey);

        $deleted = $mapper->deleteByUri($uri);

        $this->debug($deleted ? 'Delete successful' : 'Delete skipped: model not found', $event, ['uri' => $uri]);
    }

    /**
     * Build an AT Protocol URI.
     */
    protected function buildUri(string $did, string $collection, string $rkey): string
    {
        return "at://{$did}/{$collection}/{$rkey}";
    }

    /**
     * Get the effective validation mode for a mapper.
     *
     * Checks mapper-specific mode first, then falls back to config.
     */
    protected function getValidationMode(RecordMapper $mapper): ?ValidationMode
    {
        $configMode = config('parity.validation.mode');

        return $mapper->validationMode() ?? match (true) {
            $configMode instanceof ValidationMode => $configMode,
            is_string($configMode) => ValidationMode::tryFrom($configMode),
            default => null,
        };
    }

    /**
     * Validate record against lexicon schema if validation is enabled.
     *
     * Returns true if record is valid or validation is disabled.
     * Returns false if record fails validation (should be skipped).
     */
    protected function validateRecord(Data $record, RecordMapper $mapper, SignalEvent $event): bool
    {
        $mode = $this->getValidationMode($mapper);

        // Validation disabled or not configured
        if (! $mode || $mode === ValidationMode::Disabled) {
            return true;
        }

        // Set validation mode on the schema validator
        if (function_exists('SocialDept\AtpSchema\schema')) {
            $schema = \SocialDept\AtpSchema\schema();
            $schema->getValidator()->setMode($mode->value);
        }

        // Validate the record
        $errors = $record->validateWithErrors();

        if (empty($errors)) {
            return true;
        }

        // Log validation failures if enabled
        if (config('parity.validation.log_failures', true)) {
            Log::warning('ParitySignal: Record failed validation', [
                'did' => $event->did,
                'collection' => $event->commit?->collection,
                'rkey' => $event->commit?->rkey,
                'validation_mode' => $mode->value,
                'errors' => $errors,
            ]);
        }

        $this->debug('Skipping upsert: record failed validation', $event, [
            'validation_mode' => $mode->value,
            'errors' => $errors,
        ]);

        return false;
    }

    /**
     * Log debug message if signal debug is enabled.
     */
    protected function debug(string $message, ?SignalEvent $event, array $extra = []): void
    {
        if (! config('signal.debug', false)) {
            return;
        }

        Log::debug("ParitySignal: {$message}", array_merge([
            'did' => $event?->did,
            'collection' => $event?->commit?->collection,
            'operation' => $event?->commit?->operation?->value ?? null,
        ], $extra));
    }

    /**
     * Sync blobs to MediaLibrary if the blob CIDs have changed.
     */
    protected function syncBlobsIfChanged(Model $model, RecordMapper $mapper, ?array $oldBlobs, ?string $did = null): void
    {
        // Check if mapper has blob fields
        if (! $mapper->hasBlobFields()) {
            return;
        }

        // Check if model supports MediaLibrary sync
        if (! method_exists($model, 'syncAtpBlobsToMedia')) {
            return;
        }

        // Compare blob CIDs - only sync if different
        $newBlobs = $model->getAttribute('atp_blobs');

        if ($this->blobsUnchanged($oldBlobs, $newBlobs)) {
            return;
        }

        try {
            $this->debug('Syncing blobs to MediaLibrary', null, [
                'model' => get_class($model),
                'model_id' => $model->getKey(),
                'did' => $did,
                'old_blobs' => $oldBlobs,
                'new_blobs' => $newBlobs,
            ]);

            $model->syncAtpBlobsToMedia($did);
        } catch (\Throwable $e) {
            Log::warning('ParitySignal: Failed to sync blobs to MediaLibrary', [
                'model' => get_class($model),
                'model_id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if blob CIDs are unchanged between old and new values.
     */
    protected function blobsUnchanged(?array $oldBlobs, ?array $newBlobs): bool
    {
        $oldCids = $this->extractBlobCids($oldBlobs ?? []);
        $newCids = $this->extractBlobCids($newBlobs ?? []);

        return $oldCids === $newCids;
    }

    /**
     * Extract blob CIDs from an atp_blobs array for comparison.
     */
    protected function extractBlobCids(array $blobs): array
    {
        $cids = [];

        foreach ($blobs as $field => $data) {
            if (isset($data['cid'])) {
                // Single blob
                $cids[$field] = $data['cid'];
            } elseif (is_array($data)) {
                // Array of blobs
                $cids[$field] = array_map(fn ($b) => $b['cid'] ?? null, $data);
            }
        }

        ksort($cids);

        return $cids;
    }
}
