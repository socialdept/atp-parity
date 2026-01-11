<?php

namespace SocialDept\AtpParity\PendingSync;

/**
 * Result of retrying pending syncs for a DID.
 */
readonly class PendingSyncRetryResult
{
    /**
     * @param  array<string>  $errors
     */
    public function __construct(
        public int $total,
        public int $succeeded,
        public int $failed,
        public int $skipped,
        public array $errors = [],
    ) {}

    /**
     * Check if any retries failed.
     */
    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    /**
     * Check if all retries succeeded (none failed or skipped).
     */
    public function allSucceeded(): bool
    {
        return $this->failed === 0 && $this->skipped === 0;
    }

    /**
     * Check if there were no pending syncs to retry.
     */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * Get the number of successfully processed items (succeeded + skipped).
     */
    public function processed(): int
    {
        return $this->succeeded + $this->skipped;
    }
}
