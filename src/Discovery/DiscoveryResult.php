<?php

namespace SocialDept\AtpParity\Discovery;

/**
 * Immutable value object representing the result of a discovery operation.
 */
readonly class DiscoveryResult
{
    public function __construct(
        public bool $success,
        public array $dids = [],
        public int $total = 0,
        public ?string $error = null,
        public bool $incomplete = false,
    ) {}

    /**
     * Check if the discovery operation succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the discovery operation failed.
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }

    /**
     * Check if the discovery was stopped before completion (e.g., limit reached).
     */
    public function isIncomplete(): bool
    {
        return $this->incomplete;
    }

    /**
     * Create a successful result.
     */
    public static function success(array $dids, bool $incomplete = false): self
    {
        return new self(
            success: true,
            dids: $dids,
            total: count($dids),
            incomplete: $incomplete,
        );
    }

    /**
     * Create a failed result.
     */
    public static function failed(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}
