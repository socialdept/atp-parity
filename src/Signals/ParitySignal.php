<?php

namespace SocialDept\AtpParity\Signals;

use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\ConflictDetector;
use SocialDept\AtpParity\Sync\ConflictResolver;
use SocialDept\AtpParity\Sync\ConflictStrategy;
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
            return;
        }

        // Apply DID filter
        $dids = $this->dids();
        if ($dids !== null && ! in_array($event->did, $dids)) {
            return;
        }

        $commit = $event->commit;

        // Apply operation filter
        $operations = $this->operations();
        if ($operations !== null) {
            $operation = $this->getOperationType($commit);
            if (! in_array($operation, $operations)) {
                return;
            }
        }

        // Apply custom filter
        if (! $this->shouldSync($event)) {
            return;
        }

        $mapper = $this->registry->forLexicon($commit->collection);

        if (! $mapper) {
            return;
        }

        if ($commit->isCreate() || $commit->isUpdate()) {
            $this->handleUpsert($event, $mapper);
        } elseif ($commit->isDelete()) {
            $this->handleDelete($event, $mapper);
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
            return;
        }

        $recordClass = $mapper->recordClass();
        $record = $recordClass::fromArray((array) $commit->record);

        $uri = $this->buildUri($event->did, $commit->collection, $commit->rkey);
        $meta = [
            'uri' => $uri,
            'cid' => $commit->cid,
        ];

        // Check for existing model and potential conflict
        $existing = $mapper->findByUri($uri);

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
                return;
            }

            // Conflict was resolved, model already updated if needed
            return;
        }

        // No conflict, proceed with normal upsert
        $mapper->upsert($record, $meta);
    }

    /**
     * Handle delete operations.
     */
    protected function handleDelete(SignalEvent $event, RecordMapper $mapper): void
    {
        $commit = $event->commit;
        $uri = $this->buildUri($event->did, $commit->collection, $commit->rkey);

        $mapper->deleteByUri($uri);
    }

    /**
     * Build an AT Protocol URI.
     */
    protected function buildUri(string $did, string $collection, string $rkey): string
    {
        return "at://{$did}/{$collection}/{$rkey}";
    }
}
