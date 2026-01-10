<?php

namespace SocialDept\AtpParity\Sync;

/**
 * Result object for reference record sync operations.
 *
 * Tracks the outcome of syncing both main and reference records together.
 */
readonly class ReferenceSyncResult
{
    public function __construct(
        public bool $success,
        public ?string $mainUri = null,
        public ?string $mainCid = null,
        public ?string $referenceUri = null,
        public ?string $referenceCid = null,
        public ?string $error = null,
    ) {}

    /**
     * Check if the sync was successful.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the sync failed.
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }

    /**
     * Check if both main and reference records were synced.
     */
    public function isFullySynced(): bool
    {
        return $this->success && $this->mainUri && $this->referenceUri;
    }

    /**
     * Check if only the main record was synced.
     */
    public function hasMainOnly(): bool
    {
        return $this->success && $this->mainUri && ! $this->referenceUri;
    }

    /**
     * Check if only the reference record was synced.
     */
    public function hasReferenceOnly(): bool
    {
        return $this->success && $this->referenceUri && ! $this->mainUri;
    }

    /**
     * Create a success result for both records.
     */
    public static function success(
        string $mainUri,
        string $mainCid,
        ?string $referenceUri = null,
        ?string $referenceCid = null
    ): self {
        return new self(
            success: true,
            mainUri: $mainUri,
            mainCid: $mainCid,
            referenceUri: $referenceUri,
            referenceCid: $referenceCid,
        );
    }

    /**
     * Create a success result for reference-only sync.
     */
    public static function referenceSuccess(
        string $referenceUri,
        string $referenceCid,
        ?string $mainUri = null,
        ?string $mainCid = null
    ): self {
        return new self(
            success: true,
            mainUri: $mainUri,
            mainCid: $mainCid,
            referenceUri: $referenceUri,
            referenceCid: $referenceCid,
        );
    }

    /**
     * Create a failure result.
     */
    public static function failed(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}
