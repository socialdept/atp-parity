<?php

namespace SocialDept\AtpParity\Contracts;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;
use SocialDept\AtpParity\Enums\ReferenceFormat;
use SocialDept\AtpSchema\Data\Data;

/**
 * Contract for mappers that handle reference records.
 *
 * Reference records point to a "main" record via a strong reference (uri + cid)
 * or a simple AT-URI. They are used to claim ownership or add metadata to
 * records using third-party lexicons.
 *
 * @template TRecord of Data
 * @template TModel of Model
 *
 * @extends RecordMapper<TRecord, TModel>
 */
interface ReferenceMapper extends RecordMapper
{
    /**
     * Get the property name that contains the reference in the record.
     *
     * Example: 'subject', 'publication', 'document'
     */
    public function referenceProperty(): string;

    /**
     * Get the reference format used by this mapper.
     *
     * Either AtUri (simple string) or StrongRef ({uri, cid}).
     */
    public function referenceFormat(): ReferenceFormat;

    /**
     * Get the column name that stores the reference record's URI.
     */
    public function referenceUriColumn(): string;

    /**
     * Get the column name that stores the reference record's CID.
     */
    public function referenceCidColumn(): string;

    /**
     * Get the mapper for the main record type (if registered).
     */
    public function mainMapper(): ?RecordMapper;

    /**
     * Get the main record's lexicon NSID.
     */
    public function mainLexicon(): string;

    /**
     * Extract the reference from a record.
     *
     * Returns StrongRef for strongref format, or constructs one from URI for at-uri format.
     */
    public function extractReference(Data $record): ?StrongRef;

    /**
     * Build the reference data from a model.
     *
     * Returns either a URI string or StrongRef array based on the format.
     *
     * @return string|array{uri: string, cid: string}
     */
    public function buildReference(Model $model): string|array;
}
