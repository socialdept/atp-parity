<?php

namespace SocialDept\AtpParity\PendingSync;

use Carbon\CarbonImmutable;
use SocialDept\AtpParity\Enums\PendingSyncOperation;

/**
 * Value object representing a pending sync operation.
 */
readonly class PendingSync
{
    public function __construct(
        public string $id,
        public string $did,
        public string $modelClass,
        public int|string $modelId,
        public PendingSyncOperation $operation,
        public ?string $referenceMapperClass,
        public CarbonImmutable $createdAt,
        public int $attempts = 0,
    ) {}

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'did' => $this->did,
            'model_class' => $this->modelClass,
            'model_id' => $this->modelId,
            'operation' => $this->operation->value,
            'reference_mapper_class' => $this->referenceMapperClass,
            'created_at' => $this->createdAt->toIso8601String(),
            'attempts' => $this->attempts,
        ];
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            did: $data['did'],
            modelClass: $data['model_class'],
            modelId: $data['model_id'],
            operation: PendingSyncOperation::from($data['operation']),
            referenceMapperClass: $data['reference_mapper_class'] ?? null,
            createdAt: CarbonImmutable::parse($data['created_at']),
            attempts: $data['attempts'] ?? 0,
        );
    }

    /**
     * Create a new instance with incremented attempts.
     */
    public function withIncrementedAttempts(): self
    {
        return new self(
            id: $this->id,
            did: $this->did,
            modelClass: $this->modelClass,
            modelId: $this->modelId,
            operation: $this->operation,
            referenceMapperClass: $this->referenceMapperClass,
            createdAt: $this->createdAt,
            attempts: $this->attempts + 1,
        );
    }

    /**
     * Check if the pending sync has expired.
     */
    public function isExpired(int $ttlSeconds): bool
    {
        return $this->createdAt->addSeconds($ttlSeconds)->isPast();
    }

    /**
     * Check if maximum attempts have been exceeded.
     */
    public function hasExceededMaxAttempts(int $maxAttempts): bool
    {
        return $this->attempts >= $maxAttempts;
    }
}
