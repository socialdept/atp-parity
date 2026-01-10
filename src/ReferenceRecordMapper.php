<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper as RecordMapperContract;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;
use SocialDept\AtpParity\Enums\ReferenceFormat;
use SocialDept\AtpSchema\Data\Data;

/**
 * Abstract base class for reference record mappers.
 *
 * Reference records point to "main" records using strong references or AT-URIs.
 * Example: app.offprint.publication pointing to site.standard.publication
 *
 * @template TRecord of Data
 * @template TModel of Model
 *
 * @extends RecordMapper<TRecord, TModel>
 * @implements ReferenceMapper<TRecord, TModel>
 */
abstract class ReferenceRecordMapper extends RecordMapper implements ReferenceMapper
{
    /**
     * The property in the record that contains the reference.
     */
    protected string $referenceProperty = 'subject';

    /**
     * The reference format (AtUri or StrongRef).
     */
    protected ReferenceFormat $referenceFormat = ReferenceFormat::StrongRef;

    /**
     * The column storing the reference record's URI.
     */
    protected string $referenceUriColumn = 'atp_reference_uri';

    /**
     * The column storing the reference record's CID.
     */
    protected string $referenceCidColumn = 'atp_reference_cid';

    /**
     * Get the main record's lexicon NSID.
     */
    abstract public function mainLexicon(): string;

    public function referenceProperty(): string
    {
        return $this->referenceProperty;
    }

    public function referenceFormat(): ReferenceFormat
    {
        return $this->referenceFormat;
    }

    public function referenceUriColumn(): string
    {
        return config('parity.references.columns.reference_uri', $this->referenceUriColumn);
    }

    public function referenceCidColumn(): string
    {
        return config('parity.references.columns.reference_cid', $this->referenceCidColumn);
    }

    public function mainMapper(): ?RecordMapperContract
    {
        $registry = app(MapperRegistry::class);

        return $registry->forLexicon($this->mainLexicon());
    }

    public function extractReference(Data $record): ?StrongRef
    {
        $property = $this->referenceProperty();
        $data = $record->toArray();

        if (! isset($data[$property])) {
            return null;
        }

        $ref = $data[$property];

        // Handle string format (at-uri)
        if (is_string($ref)) {
            return new StrongRef(uri: $ref, cid: '');
        }

        // Handle object/array format (strongref)
        if (is_array($ref) && isset($ref['uri'])) {
            return new StrongRef(
                uri: $ref['uri'],
                cid: $ref['cid'] ?? ''
            );
        }

        return null;
    }

    /**
     * Build the reference data from a model.
     *
     * Uses the main record's atp_uri (and atp_cid for strongref format)
     * to build the reference that points to it.
     *
     * @return string|array{uri: string, cid: string}
     */
    public function buildReference(Model $model): string|array
    {
        if ($this->referenceFormat === ReferenceFormat::AtUri) {
            return $this->buildAtUriRef($model);
        }

        return $this->buildStrongRef($model)->toArray();
    }

    /**
     * Build a StrongRef from the model's main record metadata.
     *
     * @throws \InvalidArgumentException If main record metadata is missing
     */
    public function buildStrongRef(Model $model): StrongRef
    {
        $mainUri = $model->{$this->uriColumn()};
        $mainCid = $model->{$this->cidColumn()};

        if (! $mainUri || ! $mainCid) {
            throw new \InvalidArgumentException(
                "Model must have both {$this->uriColumn()} and {$this->cidColumn()} set to build a strong reference"
            );
        }

        return new StrongRef(uri: $mainUri, cid: $mainCid);
    }

    /**
     * Build an AT-URI string from the model's main record metadata.
     *
     * @throws \InvalidArgumentException If main record URI is missing
     */
    public function buildAtUriRef(Model $model): string
    {
        $mainUri = $model->{$this->uriColumn()};

        if (! $mainUri) {
            throw new \InvalidArgumentException(
                "Model must have {$this->uriColumn()} set to build an AT-URI reference"
            );
        }

        return $mainUri;
    }

    /**
     * Apply reference metadata from the record to model attributes.
     */
    protected function applyReferenceToAttributes(array $attributes, Data $record): array
    {
        // Reference records store their URI in the reference columns, not main columns
        // The main columns (atp_uri, atp_cid) store what they point TO
        $ref = $this->extractReference($record);

        if ($ref) {
            $attributes[$this->uriColumn()] = $ref->uri;

            if ($ref->cid) {
                $attributes[$this->cidColumn()] = $ref->cid;
            }
        }

        return $attributes;
    }

    /**
     * Find model by reference record URI.
     */
    public function findByReferenceUri(string $uri): ?Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::where($this->referenceUriColumn(), $uri)->first();
    }
}
