<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpParity\Contracts\DeferredReferenceStore;
use SocialDept\AtpParity\Contracts\RecordMapper as RecordMapperContract;
use SocialDept\AtpParity\Enums\ValidationMode;
use SocialDept\AtpParity\Events\DeferredReferenceResolved;
use SocialDept\AtpSchema\Data\BlobReference;
use SocialDept\AtpSchema\Data\Data;

/**
 * Abstract base class for bidirectional Record <-> Model mapping.
 *
 * @template TRecord of Data
 * @template TModel of Model
 *
 * @implements RecordMapperContract<TRecord, TModel>
 */
abstract class RecordMapper implements RecordMapperContract
{
    /**
     * Get the Record class this mapper handles.
     *
     * @return class-string<TRecord>
     */
    abstract public function recordClass(): string;

    /**
     * Get the Model class this mapper handles.
     *
     * @return class-string<TModel>
     */
    abstract public function modelClass(): string;

    /**
     * Map record properties to model attributes.
     *
     * @param  TRecord  $record
     * @return array<string, mixed>
     */
    abstract protected function recordToAttributes(Data $record): array;

    /**
     * Map model attributes to record properties.
     *
     * @param  TModel  $model
     * @return array<string, mixed>
     */
    abstract protected function modelToRecordData(Model $model): array;

    /**
     * Get the lexicon NSID this mapper handles.
     */
    public function lexicon(): string
    {
        $recordClass = $this->recordClass();

        return $recordClass::getLexicon();
    }

    /**
     * Get the column name for storing the AT Protocol URI.
     */
    protected function uriColumn(): string
    {
        return config('atp-parity.columns.uri', 'atp_uri');
    }

    /**
     * Get the column name for storing the AT Protocol CID.
     */
    protected function cidColumn(): string
    {
        return config('atp-parity.columns.cid', 'atp_cid');
    }

    /**
     * Get the column name for storing the sync timestamp.
     */
    protected function syncedAtColumn(): string
    {
        return config('atp-parity.columns.synced_at', 'atp_synced_at');
    }

    /**
     * Get the column name for storing the record's rkey, or null to skip it.
     *
     * Opt-in: only apps that read the rkey back — for routing, reconciliation,
     * or building canonical paths — need it stored, and an app that assigns the
     * rkey itself on create must have imports overwrite it with the real one.
     */
    protected function rkeyColumn(): ?string
    {
        return config('atp-parity.columns.rkey');
    }

    public function toModel(Data $record, array $meta = []): Model
    {
        $modelClass = $this->modelClass();
        $attributes = $this->recordToAttributes($record);
        $attributes = $this->applyMeta($attributes, $meta);

        return new $modelClass($attributes);
    }

    public function toRecord(Model $model): Data
    {
        $recordClass = $this->recordClass();

        return $recordClass::fromArray($this->modelToRecordData($model));
    }

    public function updateModel(Model $model, Data $record, array $meta = []): Model
    {
        $attributes = $this->recordToAttributes($record);
        $attributes = $this->applyMeta($attributes, $meta);
        $model->fill($attributes);

        return $model;
    }

    public function findByUri(string $uri): ?Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::where($this->uriColumn(), $uri)->first();
    }

    /**
     * Determine if a record should be imported.
     *
     * Override this method to add custom import conditions.
     * Return false to skip importing this record.
     */
    public function shouldImport(Data $record, array $meta = []): bool
    {
        return true;
    }

    /**
     * Get the validation mode for incoming records.
     *
     * Override this method to set a per-mapper validation mode.
     * Return null to use the global config value.
     */
    public function validationMode(): ?ValidationMode
    {
        return null; // Use global config
    }

    public function upsert(Data $record, array $meta = []): ?Model
    {
        $uri = $meta['uri'] ?? null;
        $existing = $uri ? $this->findByUri($uri) : null;

        // Resolved before the gate and handed over in `$meta['existing']`, so a
        // mapper can tell a create from an update without querying again — the
        // same row was otherwise fetched three times per event. Passed through
        // meta rather than a fourth parameter: changing the signature would
        // break every mapper that overrides this, in every consuming app.
        if (! $this->shouldImport($record, $meta + ['existing' => $existing])) {
            return null;
        }

        if ($uri) {
            if ($existing) {
                $this->updateModel($existing, $record, $meta);
                $existing->save();
                $this->afterUpsert($existing, $record, $meta, created: false);

                return $existing;
            }
        }

        $model = $this->toModel($record, $meta);
        $model->save();

        // A create is the only moment a parked reference becomes actionable: if
        // the target had existed, the reference would have applied directly.
        // Updates skip this entirely.
        $this->replayDeferredReferences($model, $meta);

        $this->afterUpsert($model, $record, $meta, created: true);

        return $model;
    }

    /**
     * Hook for state that cannot be expressed as fillable attributes.
     *
     * `recordToAttributes()` can only describe columns on the row itself, so a
     * record whose content belongs in related tables — revisions, snapshots,
     * translations, attachments — has nowhere to put it and is silently dropped
     * by `fill()`. This runs once the model is persisted and has a key.
     *
     * `$created` distinguishes the two cases that usually need different
     * handling: seeding initial state versus recording a subsequent change.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function afterUpsert(Model $model, Data $record, array $meta, bool $created): void
    {
        // No-op by default.
    }

    /**
     * Apply any reference records that arrived before this model existed.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function replayDeferredReferences(Model $model, array $meta): void
    {
        $uri = $meta['uri'] ?? null;

        if (! $uri || ! config('atp-parity.deferred_references.enabled', true)) {
            return;
        }

        $store = app(DeferredReferenceStore::class);
        $registry = app(MapperRegistry::class);

        try {
            $awaiting = $store->awaiting($uri);
        } catch (\Throwable $e) {
            // Never let replay break the create it follows. The commonest cause
            // is an app that upgraded without publishing the migration; the
            // record itself is still saved and correct.
            Log::warning('[Parity] Deferred reference store unavailable', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($awaiting as $deferred) {
            $mapper = $registry->forLexicon($deferred->collection);

            // The mapper went away (unregistered collection) — drop it rather
            // than leaving it parked forever.
            if (! $mapper) {
                $store->release($deferred->referenceUri);

                continue;
            }

            try {
                $recordClass = $mapper->recordClass();
                $mapper->upsert($recordClass::fromArray($deferred->record), $deferred->meta());
            } catch (\Throwable $e) {
                // Leave it parked: a malformed body or a transient failure should
                // not consume the reference. The TTL sweep is the backstop.
                Log::warning('[Parity] Deferred reference replay failed', [
                    'reference_uri' => $deferred->referenceUri,
                    'target_uri' => $deferred->targetUri,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $store->release($deferred->referenceUri);

            event(new DeferredReferenceResolved($deferred->referenceUri, $deferred->targetUri));
        }
    }

    public function deleteByUri(string $uri): bool
    {
        $model = $this->findByUri($uri);

        if ($model) {
            return (bool) $model->delete();
        }

        return false;
    }

    /**
     * Apply AT Protocol metadata to attributes.
     */
    protected function applyMeta(array $attributes, array $meta): array
    {
        if (isset($meta['uri'])) {
            $attributes[$this->uriColumn()] = $meta['uri'];
        }

        if (isset($meta['cid'])) {
            $attributes[$this->cidColumn()] = $meta['cid'];
        }

        if (isset($meta['rkey']) && ($rkeyColumn = $this->rkeyColumn())) {
            $attributes[$rkeyColumn] = $meta['rkey'];
        }

        // Always set synced_at when applying meta
        $attributes[$this->syncedAtColumn()] = now();

        return $attributes;
    }

    /**
     * Define blob fields in the record.
     * Override to specify which fields contain blobs.
     *
     * @return array<string, array{type: 'single'|'array', path?: string}>
     */
    public function blobFields(): array
    {
        return [];
    }

    /**
     * Extract blob references from a record.
     *
     * @return array<BlobReference>
     */
    public function extractBlobs(Data $record): array
    {
        $blobs = [];
        $fields = $this->blobFields();

        if (empty($fields)) {
            return $blobs;
        }

        $recordData = $record->toArray();

        foreach ($fields as $field => $config) {
            $path = $config['path'] ?? $field;
            $value = data_get($recordData, $path);

            if ($config['type'] === 'array' && is_array($value)) {
                foreach ($value as $item) {
                    if ($ref = $this->toBlobReference($item)) {
                        $blobs[] = $ref;
                    }
                }
            } elseif ($ref = $this->toBlobReference($value)) {
                $blobs[] = $ref;
            }
        }

        return $blobs;
    }

    /**
     * Convert array data to BlobReference.
     */
    protected function toBlobReference(mixed $data): ?BlobReference
    {
        if ($data instanceof BlobReference) {
            return $data;
        }

        if (is_array($data) && isset($data['$type']) && $data['$type'] === 'blob') {
            return BlobReference::fromArray($data);
        }

        // Handle nested blob format (e.g., image.blob)
        if (is_array($data) && isset($data['ref'])) {
            return new BlobReference(
                ref: is_array($data['ref']) ? ($data['ref']['$link'] ?? $data['ref']) : $data['ref'],
                mimeType: $data['mimeType'] ?? 'application/octet-stream',
                size: $data['size'] ?? 0
            );
        }

        return null;
    }

    /**
     * Check if this mapper has blob fields defined.
     */
    public function hasBlobFields(): bool
    {
        return ! empty($this->blobFields());
    }
}
