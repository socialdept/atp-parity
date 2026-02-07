<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper as RecordMapperContract;
use SocialDept\AtpParity\Enums\ValidationMode;
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
        // Check if import should proceed
        if (! $this->shouldImport($record, $meta)) {
            return null;
        }

        $uri = $meta['uri'] ?? null;

        if ($uri) {
            $existing = $this->findByUri($uri);

            if ($existing) {
                $this->updateModel($existing, $record, $meta);
                $existing->save();

                return $existing;
            }
        }

        $model = $this->toModel($record, $meta);
        $model->save();

        return $model;
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
