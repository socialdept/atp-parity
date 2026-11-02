<?php

namespace SocialDept\AtpParity\Signals;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\Data\IncomingRecord;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\RecordApplier;
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
 * - Filter by DID: config('atp-parity.sync.dids') or override dids()
 * - Filter by operation: config('atp-parity.sync.operations') or override operations()
 * - Custom filter: config('atp-parity.sync.filter') or override shouldSync()
 *
 * Applying the record is {@see RecordApplier}'s job, shared with any other
 * transport. This class only adapts commit events and filters them.
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
    public function __construct(
        protected MapperRegistry $registry,
        protected RecordApplier $applier,
    ) {
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
        return config('atp-parity.sync.dids');
    }

    /**
     * Get the operations to sync (null = all operations).
     *
     * Possible values: 'create', 'update', 'delete'
     * Override this method for custom operation filtering.
     */
    public function operations(): ?array
    {
        return config('atp-parity.sync.operations');
    }

    /**
     * Determine if the event should be synced.
     *
     * Override this method for custom filtering logic.
     */
    public function shouldSync(SignalEvent $event): bool
    {
        // Check custom filter callback from config
        $filter = config('atp-parity.sync.filter');
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

        try {
            if ($commit->isCreate() || $commit->isUpdate()) {
                $this->handleUpsert($event, $mapper);
            } elseif ($commit->isDelete()) {
                $this->handleDelete($event, $mapper);
            }
        } catch (\Throwable $e) {
            Log::error('[Parity:Signal] Error processing event', [
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
     * Handle create or update operations. Override to wrap the apply.
     */
    protected function handleUpsert(SignalEvent $event, RecordMapper $mapper): void
    {
        $outcome = $this->applier->apply($this->incomingRecord($event));

        $this->debug("Upsert: {$outcome->value}", $event);
    }

    /**
     * Handle delete operations. Override to wrap the apply.
     */
    protected function handleDelete(SignalEvent $event, RecordMapper $mapper): void
    {
        $outcome = $this->applier->apply($this->incomingRecord($event));

        $this->debug("Delete: {$outcome->value}", $event, [
            'uri' => $this->buildUri($event->did, $event->commit->collection, $event->commit->rkey),
        ]);
    }

    /**
     * The transport-agnostic form of a commit event.
     */
    protected function incomingRecord(SignalEvent $event): IncomingRecord
    {
        $commit = $event->commit;

        if ($commit->isDelete()) {
            return IncomingRecord::deletion($event->did, $commit->collection, $commit->rkey, (bool) $event->backfill);
        }

        return new IncomingRecord(
            did: $event->did,
            collection: $commit->collection,
            rkey: $commit->rkey,
            cid: $commit->cid,
            record: $commit->record ? (array) $commit->record : null,
            // INFO: Jetstream and the firehose leave this null, which must read as live.
            backfill: (bool) $event->backfill,
        );
    }

    /**
     * Build an AT Protocol URI.
     */
    protected function buildUri(string $did, string $collection, string $rkey): string
    {
        return "at://{$did}/{$collection}/{$rkey}";
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
     * Log debug message if signal debug is enabled.
     */
    protected function debug(string $message, ?SignalEvent $event, array $extra = []): void
    {
        if (! config('atp-signals.debug', false)) {
            return;
        }

        Log::debug("[Parity:Signal] {$message}", array_merge([
            'did' => $event?->did,
            'collection' => $event?->commit?->collection,
            'operation' => $event?->commit?->operation?->value ?? null,
        ], $extra));
    }
}
