<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpSchema\Data\Data;

/**
 * Trait for Eloquent models that map to AT Protocol records.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasAtpRecord
{
    /**
     * Get the AT Protocol URI for this model.
     */
    public function getAtpUri(): ?string
    {
        $column = config('parity.columns.uri', 'atp_uri');

        return $this->getAttribute($column);
    }

    /**
     * Get the AT Protocol CID for this model.
     */
    public function getAtpCid(): ?string
    {
        $column = config('parity.columns.cid', 'atp_cid');

        return $this->getAttribute($column);
    }

    /**
     * Get the DID from the AT Protocol URI.
     */
    public function getAtpDid(): ?string
    {
        $uri = $this->getAtpUri();

        if (! $uri) {
            return null;
        }

        // at://did:plc:xxx/app.bsky.feed.post/rkey
        if (preg_match('#^at://([^/]+)/#', $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get the collection (lexicon NSID) from the AT Protocol URI.
     */
    public function getAtpCollection(): ?string
    {
        $uri = $this->getAtpUri();

        if (! $uri) {
            return null;
        }

        // at://did:plc:xxx/app.bsky.feed.post/rkey
        if (preg_match('#^at://[^/]+/([^/]+)/#', $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get the rkey from the AT Protocol URI.
     */
    public function getAtpRkey(): ?string
    {
        $uri = $this->getAtpUri();

        if (! $uri) {
            return null;
        }

        // at://did:plc:xxx/app.bsky.feed.post/rkey
        if (preg_match('#^at://[^/]+/[^/]+/([^/]+)$#', $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if this model has been synced to AT Protocol.
     */
    public function hasAtpRecord(): bool
    {
        return $this->getAtpUri() !== null;
    }

    /**
     * Get the main (non-reference) mapper for this model.
     */
    public function getAtpMapper(): ?RecordMapper
    {
        $registry = app(MapperRegistry::class);

        foreach ($registry->forModelAll(static::class) as $mapper) {
            if (! $mapper instanceof ReferenceMapper) {
                return $mapper;
            }
        }

        // Fallback to first mapper if all are reference mappers
        return $registry->forModel(static::class);
    }

    /**
     * Convert this model to an AT Protocol record DTO.
     */
    public function toAtpRecord(): ?Data
    {
        $mapper = $this->getAtpMapper();

        if (! $mapper) {
            return null;
        }

        return $mapper->toRecord($this);
    }

    /**
     * Scope to query models that have been synced to AT Protocol.
     */
    public function scopeWithAtpRecord($query)
    {
        $column = config('parity.columns.uri', 'atp_uri');

        return $query->whereNotNull($column);
    }

    /**
     * Scope to query models that have not been synced to AT Protocol.
     */
    public function scopeWithoutAtpRecord($query)
    {
        $column = config('parity.columns.uri', 'atp_uri');

        return $query->whereNull($column);
    }

    /**
     * Scope to find by AT Protocol URI.
     */
    public function scopeWhereAtpUri($query, string $uri)
    {
        $column = config('parity.columns.uri', 'atp_uri');

        return $query->where($column, $uri);
    }
}
