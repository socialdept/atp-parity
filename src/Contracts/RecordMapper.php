<?php

namespace SocialDept\AtpParity\Contracts;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Enums\ValidationMode;
use SocialDept\AtpSchema\Data\Data;

/**
 * Contract for bidirectional mapping between Record DTOs and Eloquent models.
 *
 * @template TRecord of Data
 * @template TModel of Model
 */
interface RecordMapper
{
    /**
     * Get the Record class this mapper handles.
     *
     * @return class-string<TRecord>
     */
    public function recordClass(): string;

    /**
     * Get the Model class this mapper handles.
     *
     * @return class-string<TModel>
     */
    public function modelClass(): string;

    /**
     * Get the lexicon NSID this mapper handles.
     */
    public function lexicon(): string;

    /**
     * Convert a Record DTO to an Eloquent Model.
     *
     * @param  TRecord  $record
     * @param  array{uri?: string, cid?: string, did?: string, rkey?: string}  $meta  AT Protocol metadata
     * @return TModel
     */
    public function toModel(Data $record, array $meta = []): Model;

    /**
     * Convert an Eloquent Model to a Record DTO.
     *
     * @param  TModel  $model
     * @return TRecord
     */
    public function toRecord(Model $model): Data;

    /**
     * Update an existing model with data from a record.
     *
     * @param  TModel  $model
     * @param  TRecord  $record
     * @param  array{uri?: string, cid?: string, did?: string, rkey?: string}  $meta
     * @return TModel
     */
    public function updateModel(Model $model, Data $record, array $meta = []): Model;

    /**
     * Determine if a record should be imported.
     *
     * Override this method to add custom import conditions.
     * Return false to skip importing this record.
     *
     * @param  TRecord  $record
     * @param  array{uri?: string, cid?: string, did?: string, rkey?: string}  $meta
     */
    public function shouldImport(Data $record, array $meta = []): bool;

    /**
     * Find or create model from record.
     *
     * Returns null if shouldImport() returns false.
     *
     * @param  TRecord  $record
     * @param  array{uri?: string, cid?: string, did?: string, rkey?: string}  $meta
     * @return TModel|null
     */
    public function upsert(Data $record, array $meta = []): ?Model;

    /**
     * Find model by AT Protocol URI.
     *
     * @return TModel|null
     */
    public function findByUri(string $uri): ?Model;

    /**
     * Delete model by AT Protocol URI.
     */
    public function deleteByUri(string $uri): bool;

    /**
     * Get the validation mode for incoming records.
     *
     * Return null to use the global config value.
     */
    public function validationMode(): ?ValidationMode;

    /**
     * Check if this mapper has blob fields defined.
     */
    public function hasBlobFields(): bool;
}
