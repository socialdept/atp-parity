<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;
use SocialDept\AtpParity\MapperRegistry;

/**
 * Trait for Eloquent models that have both a main record and a reference record.
 *
 * This approach uses a single model with dual URI columns:
 * - atp_uri / atp_cid: The main record (third-party lexicon)
 * - atp_reference_uri / atp_reference_cid: The reference record (your platform's lexicon)
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasReferenceRecord
{
    use HasAtpRecord;

    /**
     * Get the column name for the reference record's URI.
     */
    public function getReferenceUriColumn(): string
    {
        return $this->referenceUriColumn
            ?? config('atp-parity.references.columns.reference_uri', 'atp_reference_uri');
    }

    /**
     * Get the column name for the reference record's CID.
     */
    public function getReferenceCidColumn(): string
    {
        return $this->referenceCidColumn
            ?? config('atp-parity.references.columns.reference_cid', 'atp_reference_cid');
    }

    /**
     * Get the reference record's URI.
     */
    public function getReferenceUri(): ?string
    {
        return $this->getAttribute($this->getReferenceUriColumn());
    }

    /**
     * Get the reference record's CID.
     */
    public function getReferenceCid(): ?string
    {
        return $this->getAttribute($this->getReferenceCidColumn());
    }

    /**
     * Get the strong reference for the reference record itself.
     */
    public function getReferenceRef(): ?StrongRef
    {
        $uri = $this->getReferenceUri();
        $cid = $this->getReferenceCid();

        if (! $uri) {
            return null;
        }

        return new StrongRef($uri, $cid ?? '');
    }

    /**
     * Get the strong reference to the main record (what the reference points to).
     */
    public function getMainRef(): ?StrongRef
    {
        $uri = $this->getAtpUri();
        $cid = $this->getAtpCid();

        if (! $uri) {
            return null;
        }

        return new StrongRef($uri, $cid ?? '');
    }

    /**
     * Check if this model has a main record (the record being referenced).
     *
     * Uses the standard atp_uri column from HasAtpRecord.
     */
    public function hasMainRecord(): bool
    {
        return $this->hasAtpRecord();
    }

    /**
     * Check if this model has a reference record (the pointer to main).
     */
    public function hasReferenceRecord(): bool
    {
        return $this->getReferenceUri() !== null;
    }

    /**
     * Check if both main and reference records exist.
     */
    public function isFullySynced(): bool
    {
        return $this->hasMainRecord() && $this->hasReferenceRecord();
    }

    /**
     * Get the reference mapper for this model.
     */
    public function getReferenceMapper(): ?ReferenceMapper
    {
        $registry = app(MapperRegistry::class);

        foreach ($registry->forModelAll(static::class) as $mapper) {
            if ($mapper instanceof ReferenceMapper) {
                return $mapper;
            }
        }

        return null;
    }

    /**
     * Get the desired rkey for reference records.
     *
     * Return null to let AT Protocol generate one automatically.
     * Override this method to provide custom reference rkeys.
     */
    public function getDesiredAtpReferenceRkey(): ?string
    {
        return null;
    }

    /**
     * Scope to query models that have reference records.
     */
    public function scopeWithReferenceRecord($query)
    {
        return $query->whereNotNull($this->getReferenceUriColumn());
    }

    /**
     * Scope to query models without reference records.
     */
    public function scopeWithoutReferenceRecord($query)
    {
        return $query->whereNull($this->getReferenceUriColumn());
    }

    /**
     * Scope to find by reference record URI.
     */
    public function scopeWhereReferenceUri($query, string $uri)
    {
        return $query->where($this->getReferenceUriColumn(), $uri);
    }

    /**
     * Scope to query models that are fully synced (have both main and reference).
     */
    public function scopeFullySynced($query)
    {
        $uriColumn = config('atp-parity.columns.uri', 'atp_uri');

        return $query->whereNotNull($uriColumn)
            ->whereNotNull($this->getReferenceUriColumn());
    }
}
