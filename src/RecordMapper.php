<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper as RecordMapperContract;
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
        return config('parity.columns.uri', 'atp_uri');
    }

    /**
     * Get the column name for storing the AT Protocol CID.
     */
    protected function cidColumn(): string
    {
        return config('parity.columns.cid', 'atp_cid');
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

    public function upsert(Data $record, array $meta = []): Model
    {
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

        return $attributes;
    }
}
