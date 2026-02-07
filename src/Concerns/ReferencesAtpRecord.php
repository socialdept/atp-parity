<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

/**
 * Trait for separate pivot/reference models that point to a main model.
 *
 * Use this when you have separate tables:
 * - documents (main records using HasAtpRecord)
 * - document_claims (reference records that point to documents)
 *
 * The reference model stores its own atp_uri/atp_cid and references
 * the main model via a foreign key relationship.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait ReferencesAtpRecord
{
    use HasAtpRecord;

    /**
     * Get the foreign key column for the main model.
     *
     * @return string
     */
    abstract public function getMainModelForeignKey(): string;

    /**
     * Get the main model class.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    abstract public function getMainModelClass(): string;

    /**
     * Relationship to the main model.
     */
    public function mainModel()
    {
        return $this->belongsTo($this->getMainModelClass(), $this->getMainModelForeignKey());
    }

    /**
     * Get the strong reference from the related main model.
     *
     * This returns the main model's AT Protocol record info,
     * which is what this reference record points to.
     */
    public function getMainRef(): ?StrongRef
    {
        $main = $this->mainModel;

        if (! $main) {
            return null;
        }

        $uriColumn = config('atp-parity.columns.uri', 'atp_uri');
        $cidColumn = config('atp-parity.columns.cid', 'atp_cid');

        $uri = $main->{$uriColumn};
        $cid = $main->{$cidColumn};

        if (! $uri) {
            return null;
        }

        return new StrongRef($uri, $cid ?? '');
    }

    /**
     * Check if the main model has been synced to AT Protocol.
     */
    public function hasMainRecord(): bool
    {
        return $this->getMainRef() !== null;
    }

    /**
     * Check if this reference record has been synced.
     */
    public function hasReferenceRecord(): bool
    {
        return $this->hasAtpRecord();
    }

    /**
     * Check if both main and reference records are synced.
     */
    public function isFullySynced(): bool
    {
        return $this->hasMainRecord() && $this->hasReferenceRecord();
    }

    /**
     * Scope to query references where the main model is synced.
     */
    public function scopeWithSyncedMain($query)
    {
        $mainClass = $this->getMainModelClass();
        $uriColumn = config('atp-parity.columns.uri', 'atp_uri');

        return $query->whereHas('mainModel', function ($q) use ($uriColumn) {
            $q->whereNotNull($uriColumn);
        });
    }

    /**
     * Scope to query references where the main model is not synced.
     */
    public function scopeWithoutSyncedMain($query)
    {
        $uriColumn = config('atp-parity.columns.uri', 'atp_uri');

        return $query->whereHas('mainModel', function ($q) use ($uriColumn) {
            $q->whereNull($uriColumn);
        });
    }
}
