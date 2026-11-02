<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\Contracts\ResolvesConflictStrategy;
use SocialDept\AtpParity\Data\IncomingRecord;
use SocialDept\AtpParity\Enums\ApplyOutcome;
use SocialDept\AtpParity\Enums\ValidationMode;
use SocialDept\AtpParity\Events\ConflictResolved;
use SocialDept\AtpParity\Events\RecordConstructionFailed;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Support\AutoSync;
use SocialDept\AtpParity\Upcasting\UpcasterChain;
use SocialDept\AtpSchema\Data\Data;

/**
 * Applies an inbound record to its model through the registered mapper.
 *
 * Every guard between a delivered record and a model write lives here, so a
 * transport only has to turn its event into an {@see IncomingRecord}:
 * `ParitySignal` does it for atp-signals, and an app can do it from any other
 * ingest pipeline without re-implementing the echo, backfill and conflict rules.
 *
 * `preview()` walks the same path and writes nothing, so an app moving to a new
 * transport can compare what it would do against what the old one does.
 */
class RecordApplier
{
    protected ConflictDetector $conflictDetector;

    protected ConflictResolver $conflictResolver;

    public function __construct(
        protected MapperRegistry $registry,
        protected UpcasterChain $upcasters,
    ) {
        $this->conflictDetector = new ConflictDetector();
        $this->conflictResolver = new ConflictResolver();
    }

    /**
     * Apply the record. A mapper exception is rethrown for the transport to handle.
     */
    public function apply(IncomingRecord $incoming): ApplyOutcome
    {
        return $this->run($incoming, write: true);
    }

    /**
     * What `apply()` would do, writing nothing and dispatching no events.
     *
     * A conflict reports {@see ApplyOutcome::Conflict} rather than running the
     * strategy, and a record that cannot be built reports
     * {@see ApplyOutcome::Unconstructable} even where `apply()` would throw.
     */
    public function preview(IncomingRecord $incoming): ApplyOutcome
    {
        return $this->run($incoming, write: false);
    }

    protected function run(IncomingRecord $incoming, bool $write): ApplyOutcome
    {
        $mapper = $this->registry->forLexicon($incoming->collection);

        if (! $mapper) {
            return ApplyOutcome::Unmapped;
        }

        if ($incoming->delete) {
            return $this->delete($incoming, $mapper, $write);
        }

        return $write
            ? AutoSync::without(fn (): ApplyOutcome => $this->upsert($incoming, $mapper, write: true))
            : $this->upsert($incoming, $mapper, write: false);
    }

    protected function upsert(IncomingRecord $incoming, RecordMapper $mapper, bool $write): ApplyOutcome
    {
        if (! $incoming->record) {
            return ApplyOutcome::Empty;
        }

        $recordClass = $mapper->recordClass();

        if (! is_subclass_of($recordClass, Data::class)) {
            return ApplyOutcome::Unconstructable;
        }

        $validationMode = $this->validationMode($mapper);

        try {
            // INFO: upcast before hydration. The DTO is the current lexicon, so an
            // older record would fail here or lose fields it does not declare.
            $record = $recordClass::fromArray($this->upcasters->upcast($incoming->collection, $incoming->record));
        } catch (\Throwable $e) {
            if (! $write) {
                return ApplyOutcome::Unconstructable;
            }

            if (! $validationMode || $validationMode === ValidationMode::Disabled) {
                throw $e;
            }

            if (config('atp-parity.validation.log_failures', true)) {
                Log::warning('[Parity] Record failed to construct', [
                    'did' => $incoming->did,
                    'collection' => $incoming->collection,
                    'rkey' => $incoming->rkey,
                    'validation_mode' => $validationMode->value,
                    'error' => $e->getMessage(),
                ]);
            }

            // Observable, not just logged: this path drops the record entirely
            // while the transport still advances, so nothing downstream can tell.
            event(new RecordConstructionFailed(
                did: $incoming->did,
                collection: $incoming->collection,
                rkey: $incoming->rkey,
                error: $e->getMessage(),
            ));

            return ApplyOutcome::Unconstructable;
        }

        if (! $this->passesValidation($record, $validationMode, $incoming, log: $write)) {
            return ApplyOutcome::Invalid;
        }

        $uri = $incoming->uri();
        $meta = [
            'uri' => $uri,
            'cid' => $incoming->cid,
            'did' => $incoming->did,
            'rkey' => $incoming->rkey,
            // Historical replay rather than a live commit. Mappers need this to
            // tell "we are catching up" from "something just changed".
            'backfill' => $incoming->backfill,
        ];

        $existing = $this->findExisting($mapper, $uri);

        if ($existing) {
            $existingCid = $existing->getAttribute($this->cidColumnFor($mapper));

            if ($existingCid !== null && $existingCid === $incoming->cid) {
                return ApplyOutcome::Unchanged;
            }
        }

        // A backfilled event for a record we already hold is us re-reading
        // history, not news. The CID check cannot catch it: a replay carries the
        // record's old CID, which never matches the latest one stored, so every
        // historical version would otherwise be re-applied in turn, and under the
        // `remote` strategy over newer local edits. `rev` cannot order it either,
        // because an archive stamps a whole backfill with one rev.
        if ($existing && $incoming->backfill && ! config('atp-parity.backfill.overwrites_existing', false)) {
            return ApplyOutcome::BackfillHeld;
        }

        // INFO: a reference record carries no content to conflict over, and the
        // resolver applies records through the main columns, which would write the
        // reference's URI over the main record's.
        if ($existing
            && ! $mapper instanceof ReferenceMapper
            && $this->conflictDetector->hasConflict($existing, $record, $incoming->cid)) {
            return $write
                ? $this->resolveConflict($existing, $record, $meta, $mapper)
                : ApplyOutcome::Conflict;
        }

        if (! $write) {
            return $mapper->shouldImport($record, $meta + ['existing' => $existing])
                ? ($existing ? ApplyOutcome::Updated : ApplyOutcome::Created)
                : ApplyOutcome::Refused;
        }

        $existingBlobs = $existing?->getAttribute('atp_blobs');
        $result = $mapper->upsert($record, $meta);

        if ($result === null) {
            return ApplyOutcome::Refused;
        }

        $this->syncBlobsIfChanged($result, $mapper, $existingBlobs, $incoming->did);

        return $result->wasRecentlyCreated ? ApplyOutcome::Created : ApplyOutcome::Updated;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveConflict(Model $existing, Data $record, array $meta, RecordMapper $mapper): ApplyOutcome
    {
        $existingBlobs = $existing->getAttribute('atp_blobs');

        // A mapper may decide per record: authority often belongs to the record's
        // provenance rather than to a global setting.
        $strategy = ($mapper instanceof ResolvesConflictStrategy
            ? $mapper->conflictStrategy($existing)
            : null) ?? ConflictStrategy::fromConfig();

        $resolution = $this->conflictResolver->resolve($existing, $record, $meta, $mapper, $strategy);

        if (! $resolution->isResolved()) {
            return ApplyOutcome::ConflictPending;
        }

        event(new ConflictResolved(
            model: $existing,
            strategy: $strategy,
            winner: $resolution->winner,
            uri: $meta['uri'],
        ));

        $this->syncBlobsIfChanged($existing, $mapper, $existingBlobs, $meta['did']);

        return ApplyOutcome::ConflictResolved;
    }

    protected function delete(IncomingRecord $incoming, RecordMapper $mapper, bool $write): ApplyOutcome
    {
        $uri = $incoming->uri();

        $found = $write
            ? $mapper->deleteByUri($uri)
            : $mapper->findByUri($uri) !== null;

        return $found ? ApplyOutcome::Deleted : ApplyOutcome::Missing;
    }

    /**
     * The model this record is already applied to, if any.
     *
     * A reference record's own URI lives in the reference column. The main URI
     * column holds what it points at, so a lookup there never finds it.
     */
    protected function findExisting(RecordMapper $mapper, string $uri): ?Model
    {
        if (! $mapper instanceof ReferenceMapper) {
            return $mapper->findByUri($uri);
        }

        return $mapper->modelClass()::where($mapper->referenceUriColumn(), $uri)->first();
    }

    protected function cidColumnFor(RecordMapper $mapper): string
    {
        return $mapper instanceof ReferenceMapper
            ? $mapper->referenceCidColumn()
            : config('atp-parity.columns.cid', 'atp_cid');
    }

    /**
     * The mapper's own validation mode, falling back to config.
     */
    protected function validationMode(RecordMapper $mapper): ?ValidationMode
    {
        $configMode = config('atp-parity.validation.mode');

        return $mapper->validationMode() ?? match (true) {
            $configMode instanceof ValidationMode => $configMode,
            is_string($configMode) => ValidationMode::tryFrom($configMode),
            default => null,
        };
    }

    protected function passesValidation(Data $record, ?ValidationMode $mode, IncomingRecord $incoming, bool $log): bool
    {
        if (! $mode || $mode === ValidationMode::Disabled) {
            return true;
        }

        if (function_exists('SocialDept\AtpSchema\schema')) {
            \SocialDept\AtpSchema\schema()->getValidator()->setMode($mode->value);
        }

        $errors = $record->validateWithErrors();

        if (empty($errors)) {
            return true;
        }

        if ($log && config('atp-parity.validation.log_failures', true)) {
            Log::warning('[Parity] Record failed validation', [
                'did' => $incoming->did,
                'collection' => $incoming->collection,
                'rkey' => $incoming->rkey,
                'validation_mode' => $mode->value,
                'errors' => $errors,
            ]);
        }

        return false;
    }

    /**
     * Sync blobs to MediaLibrary if the blob CIDs have changed.
     */
    protected function syncBlobsIfChanged(Model $model, RecordMapper $mapper, ?array $oldBlobs, ?string $did = null): void
    {
        if (! $mapper->hasBlobFields() || ! method_exists($model, 'syncAtpBlobsToMedia')) {
            return;
        }

        if ($this->blobsUnchanged($oldBlobs, $model->getAttribute('atp_blobs'))) {
            return;
        }

        try {
            $model->syncAtpBlobsToMedia($did);
        } catch (\Throwable $e) {
            Log::warning('[Parity] Failed to sync blobs to MediaLibrary', [
                'model' => get_class($model),
                'model_id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function blobsUnchanged(?array $oldBlobs, ?array $newBlobs): bool
    {
        return $this->extractBlobCids($oldBlobs ?? []) === $this->extractBlobCids($newBlobs ?? []);
    }

    /**
     * @param  array<string, mixed>  $blobs
     * @return array<string, mixed>
     */
    protected function extractBlobCids(array $blobs): array
    {
        $cids = [];

        foreach ($blobs as $field => $data) {
            if (isset($data['cid'])) {
                $cids[$field] = $data['cid'];
            } elseif (is_array($data)) {
                $cids[$field] = array_map(fn ($b) => $b['cid'] ?? null, $data);
            }
        }

        ksort($cids);

        return $cids;
    }
}
