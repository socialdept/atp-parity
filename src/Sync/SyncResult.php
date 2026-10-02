<?php

namespace SocialDept\AtpParity\Sync;

/**
 * Immutable value object representing the result of a sync operation.
 */
readonly class SyncResult
{
    public function __construct(
        public bool $success,
        public ?string $uri = null,
        public ?string $cid = null,
        public ?string $error = null,
        /**
         * The repo already held this exact record, so nothing was written.
         *
         * Still a success: the record is in the state the caller asked for, so a
         * caller that only checks `isSuccess()` stays correct.
         */
        public bool $unchanged = false,
    ) {
    }

    /**
     * Check if the sync operation succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the sync operation failed.
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }

    /**
     * Create a successful result.
     */
    public static function success(string $uri, string $cid): self
    {
        return new self(
            success: true,
            uri: $uri,
            cid: $cid,
        );
    }

    /**
     * Create a result for a record the repo already held byte for byte.
     */
    public static function unchanged(string $uri, string $cid): self
    {
        return new self(
            success: true,
            uri: $uri,
            cid: $cid,
            unchanged: true,
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
