<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use SocialDept\AtpParity\PendingSync\PendingSyncRetryResult;
use SocialDept\AtpSchema\Data\Data;

/**
 * Registry for RecordMapper instances.
 *
 * Allows looking up mappers by Record class, Model class, or lexicon NSID.
 */
class MapperRegistry
{
    /** @var array<class-string<Data>, RecordMapper> */
    protected array $byRecord = [];

    /** @var array<class-string<Model>, array<RecordMapper>> */
    protected array $byModel = [];

    /** @var array<string, RecordMapper> Keyed by NSID */
    protected array $byLexicon = [];

    /**
     * Register a mapper.
     */
    public function register(RecordMapper $mapper): void
    {
        $recordClass = $mapper->recordClass();
        $modelClass = $mapper->modelClass();

        $this->byRecord[$recordClass] = $mapper;
        $this->byModel[$modelClass][] = $mapper;
        $this->byLexicon[$mapper->lexicon()] = $mapper;
    }

    /**
     * Get a mapper by Record class.
     *
     * @param  class-string<Data>  $recordClass
     */
    public function forRecord(string $recordClass): ?RecordMapper
    {
        return $this->byRecord[$recordClass] ?? null;
    }

    /**
     * Get the first mapper for a Model class.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function forModel(string $modelClass): ?RecordMapper
    {
        return $this->byModel[$modelClass][0] ?? null;
    }

    /**
     * Get all mappers for a Model class.
     *
     * Useful when a model has multiple mappers (e.g., main + reference records).
     *
     * @param  class-string<Model>  $modelClass
     * @return array<RecordMapper>
     */
    public function forModelAll(string $modelClass): array
    {
        return $this->byModel[$modelClass] ?? [];
    }

    /**
     * Get a mapper by lexicon NSID.
     */
    public function forLexicon(string $nsid): ?RecordMapper
    {
        return $this->byLexicon[$nsid] ?? null;
    }

    /**
     * Check if a mapper exists for the given lexicon.
     */
    public function hasLexicon(string $nsid): bool
    {
        return isset($this->byLexicon[$nsid]);
    }

    /**
     * Get all registered lexicon NSIDs.
     *
     * @return array<string>
     */
    public function lexicons(): array
    {
        return array_keys($this->byLexicon);
    }

    /**
     * Get all registered mappers.
     *
     * @return array<RecordMapper>
     */
    public function all(): array
    {
        return array_values($this->byLexicon);
    }

    /**
     * Retry all pending syncs for a DID.
     *
     * Convenience method that delegates to PendingSyncManager.
     */
    public function retryPendingSyncs(string $did): PendingSyncRetryResult
    {
        return app(PendingSyncManager::class)->retryForDid($did);
    }

    /**
     * Check if a DID has pending syncs.
     *
     * Convenience method that delegates to PendingSyncManager.
     */
    public function hasPendingSyncs(string $did): bool
    {
        return app(PendingSyncManager::class)->hasPendingSyncs($did);
    }

    /**
     * Get pending syncs count for a DID.
     *
     * Convenience method that delegates to PendingSyncManager.
     */
    public function countPendingSyncs(string $did): int
    {
        return app(PendingSyncManager::class)->countForDid($did);
    }
}
